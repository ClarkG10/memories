<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\MemoryMedia;
use App\Services\GoogleDrive\GoogleDriveException;
use App\Services\GoogleDrive\GoogleDriveService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Display-sized copies of the originals, cached on the server's own disk.
 *
 * Drive holds the originals and must not be asked for them on every scroll:
 * it is rate limited, slow for repeated reads, and a full-resolution photo is
 * many times larger than anything a screen needs. The first request for a
 * given file pulls it down once, renders the whole size ladder, and every
 * request after that is served straight from disk.
 *
 * This is a cache, not storage. Deleting the directory loses nothing.
 */
class DerivativeService
{
    public function __construct(
        private readonly GoogleDriveService $drive,
    ) {}

    /**
     * Absolute path to a JPEG of this image at (about) the requested width,
     * or null if no rendering is possible.
     */
    public function imagePath(MemoryMedia $media, int $requestedWidth): ?string
    {
        if (! $media->isImage()) {
            return null;
        }

        $size = $this->normaliseWidth($requestedWidth);
        $relative = "{$media->uuid}/w{$size}.jpg";

        if ($this->disk()->exists($relative)) {
            return $this->disk()->path($relative);
        }

        return $this->withLock("derivative:{$media->uuid}", function () use ($media, $relative, $size): ?string {
            // Another request may have finished the work while we queued.
            if ($this->disk()->exists($relative)) {
                return $this->disk()->path($relative);
            }

            if (! $this->renderLadder($media)) {
                return null;
            }

            if ($this->disk()->exists($relative)) {
                return $this->disk()->path($relative);
            }

            // The original was narrower than the size asked for, so the ladder
            // stopped short. Hand back the largest rendition that exists.
            return $this->largestRendition($media, $size);
        });
    }

    /**
     * A still frame representing a video, taken from Drive's own generated
     * thumbnail. Returns null while Drive is still processing the upload — the
     * timeline has a designed fallback for exactly that case.
     */
    public function posterPath(MemoryMedia $media): ?string
    {
        $relative = "{$media->uuid}/poster.jpg";

        if ($this->disk()->exists($relative)) {
            return $this->disk()->path($relative);
        }

        return $this->withLock("poster:{$media->uuid}", function () use ($media, $relative): ?string {
            if ($this->disk()->exists($relative)) {
                return $this->disk()->path($relative);
            }

            try {
                // Stored thumbnail links expire, so always ask Drive for a
                // fresh one before fetching.
                $url = $this->drive->thumbnailUrl($media->drive_file_id);
            } catch (GoogleDriveException $e) {
                Log::warning('Could not read a Drive thumbnail link.', [
                    'media_uuid' => $media->uuid,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            if ($url === null) {
                return null;
            }

            $bytes = $this->fetchThumbnail($url);

            if ($bytes === null) {
                return null;
            }

            $this->disk()->put($relative, $bytes);

            if ($media->drive_thumbnail_url !== $url) {
                $media->forceFill(['drive_thumbnail_url' => $url])->saveQuietly();
            }

            return $this->disk()->path($relative);
        });
    }

    /**
     * Store a poster this server has already produced — a frame captured by
     * the browser at upload time — so the proxy never has to wait on Drive.
     */
    public function storePoster(MemoryMedia $media, string $jpeg): void
    {
        $this->disk()->put("{$media->uuid}/poster.jpg", $jpeg);
    }

    /**
     * Drop every cached rendition of a piece of media. Called when it is
     * removed, so nothing survives the deletion.
     */
    public function forget(MemoryMedia $media): void
    {
        $this->disk()->deleteDirectory($media->uuid);
    }

    /**
     * Pull the original down once and write out every configured size that
     * makes sense for it.
     */
    private function renderLadder(MemoryMedia $media): bool
    {
        $temp = tempnam(sys_get_temp_dir(), 'memories-src-');

        if ($temp === false) {
            return false;
        }

        try {
            if (! $this->downloadOriginal($media, $temp)) {
                return false;
            }

            /*
             | Decoding costs roughly four bytes of memory per pixel, and this
             | runs inside a web request. A photograph large enough to exhaust
             | the process falls back to Drive's own thumbnail rather than
             | taking the worker down with it.
             */
            $dimensions = @getimagesize($temp);

            if ($dimensions !== false && $dimensions[0] * $dimensions[1] > MediaInspector::MAX_DECODE_PIXELS) {
                Log::info('Photograph too large to resize here; using the Drive thumbnail.', [
                    'media_uuid' => $media->uuid,
                    'pixels' => $dimensions[0] * $dimensions[1],
                ]);

                return $this->renderFromDriveThumbnail($media);
            }

            $source = ImageEditor::open($temp);

            if ($source === null) {
                // A format GD cannot decode (HEIC). Drive's thumbnail is the
                // only rendition available, so reuse the video path.
                return $this->renderFromDriveThumbnail($media);
            }

            $originalWidth = imagesx($source);
            $quality = (int) config('memories.derivatives.quality', 82);
            $sizes = $this->sizes();
            $written = false;

            foreach ($sizes as $index => $width) {
                // Never upscale.
                $target = min($width, $originalWidth);
                $rendition = ImageEditor::resizeToWidth($source, $target);
                $jpeg = ImageEditor::toJpeg($rendition, $quality);

                $this->write($media, $width, $jpeg);

                unset($rendition);
                $written = true;

                if ($target !== $originalWidth) {
                    continue;
                }

                /*
                 | This rung is the original at full size, so every larger one
                 | would be identical. They still have to exist: a missing file
                 | is a cache miss, and a cache miss here means downloading the
                 | original from Drive again — on every single request for the
                 | full-size view, forever, for any photograph narrower than
                 | the top rung.
                 */
                foreach (array_slice($sizes, $index + 1) as $larger) {
                    $this->write($media, $larger, $jpeg);
                }

                break;
            }

            unset($source);

            return $written;
        } catch (GoogleDriveException $e) {
            /*
             | Deliberately not swallowed. "Drive is unreachable" and "this
             | photograph cannot be rendered" are different answers: swallowing
             | it here turns a Drive outage into a 404 on every photograph, so
             | the browser and any monitoring both read a temporary problem as
             | "these memories are gone".
             */
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Could not render image derivatives.', [
                'media_uuid' => $media->uuid,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Fallback ladder for originals this server cannot decode: one size, taken
     * from Drive's thumbnail, copied across the size names so requests resolve.
     */
    private function renderFromDriveThumbnail(MemoryMedia $media): bool
    {
        try {
            $url = $this->drive->thumbnailUrl($media->drive_file_id);
        } catch (GoogleDriveException $e) {
            Log::warning('Could not fall back to a Drive thumbnail.', [
                'media_uuid' => $media->uuid,
                'status' => $e->status,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($url === null) {
            return false;
        }

        $bytes = $this->fetchThumbnail($url);

        if ($bytes === null) {
            return false;
        }

        foreach ($this->sizes() as $width) {
            $this->write($media, $width, $bytes);
        }

        return true;
    }

    /**
     * Put one rendition in place, atomically.
     *
     * Written to a temporary name and renamed, which on a local filesystem is
     * atomic: a request arriving mid-write sees either the finished file or no
     * file, never half a JPEG — which would otherwise be served with a
     * one-year immutable cache header and stay broken in that browser.
     */
    private function write(MemoryMedia $media, int $width, string $jpeg): void
    {
        $final = "{$media->uuid}/w{$width}.jpg";
        $temp = sprintf('%s/.w%d.%s.tmp', $media->uuid, $width, bin2hex(random_bytes(4)));

        $this->disk()->put($temp, $jpeg);

        if (! @rename($this->disk()->path($temp), $this->disk()->path($final))) {
            $this->disk()->delete($temp);

            Log::warning('Could not put an image rendition in place.', [
                'media_uuid' => $media->uuid,
                'width' => $width,
            ]);
        }
    }

    /**
     * @throws GoogleDriveException when Drive itself cannot be reached
     */
    private function downloadOriginal(MemoryMedia $media, string $destination): bool
    {
        // Any GoogleDriveException propagates: see the note in renderLadder.
        $response = $this->drive->download($media->drive_file_id);

        if ($response->getStatusCode() !== 200) {
            Log::warning('Drive refused to serve an original.', [
                'media_uuid' => $media->uuid,
                'status' => $response->getStatusCode(),
            ]);

            return false;
        }

        $handle = fopen($destination, 'wb');

        if ($handle === false) {
            return false;
        }

        try {
            $body = $response->getBody();

            // Copy in blocks so a large original never lands in memory whole.
            while (! $body->eof()) {
                fwrite($handle, $body->read(262144));
            }
        } finally {
            fclose($handle);
        }

        return filesize($destination) > 0;
    }

    /**
     * Drive thumbnail links carry a size suffix (=s220). Ask for something big
     * enough to use as a poster on a retina screen.
     */
    private function fetchThumbnail(string $url): ?string
    {
        $url = preg_replace('/=s\d+(-c)?$/', '=s1600', $url) ?? $url;

        try {
            $response = $this->drive->downloadUrl($url);
        } catch (Throwable $e) {
            Log::warning('Could not fetch a Drive thumbnail.', ['error' => $e->getMessage()]);

            return null;
        }

        $bytes = (string) $response->getBody();

        return $bytes === '' ? null : $bytes;
    }

    private function largestRendition(MemoryMedia $media, int $requested): ?string
    {
        foreach (array_reverse($this->sizes()) as $width) {
            if ($width > $requested) {
                continue;
            }

            $relative = "{$media->uuid}/w{$width}.jpg";

            if ($this->disk()->exists($relative)) {
                return $this->disk()->path($relative);
            }
        }

        foreach ($this->sizes() as $width) {
            $relative = "{$media->uuid}/w{$width}.jpg";

            if ($this->disk()->exists($relative)) {
                return $this->disk()->path($relative);
            }
        }

        return null;
    }

    /**
     * Round a requested width up to the next size actually rendered, so the
     * cache holds a handful of files per image rather than one per viewport.
     */
    private function normaliseWidth(int $requested): int
    {
        $sizes = $this->sizes();

        foreach ($sizes as $size) {
            if ($requested <= $size) {
                return $size;
            }
        }

        return (int) end($sizes);
    }

    /**
     * @return array<int, int>
     */
    private function sizes(): array
    {
        $sizes = array_map('intval', (array) config('memories.derivatives.sizes'));
        sort($sizes);

        return $sizes;
    }

    /**
     * Rendering the same image in two requests at once wastes a Drive download
     * and can interleave writes. The second caller waits and then finds the
     * finished file.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function withLock(string $key, callable $callback): mixed
    {
        $lock = Cache::lock("media-lock:{$key}", 120);

        try {
            $lock->block(30);
        } catch (Throwable) {
            /*
             | Thirty seconds waiting for whoever is rendering this file, and
             | they never finished. The caller turns this into "not available",
             | which from the outside is indistinguishable from a photograph
             | that does not exist — so it is said plainly here instead.
             */
            Log::warning('Gave up waiting to render a derivative.', ['lock' => $key]);

            return null;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('memories.derivatives.disk', 'derivatives'));
    }
}
