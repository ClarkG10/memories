<?php

declare(strict_types=1);

namespace App\Services\Media;

use finfo;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides what a file actually is, and gathers everything the timeline needs
 * to know about it, while the file is still on local disk.
 *
 * Doing this before the Drive upload means the expensive network transfer only
 * ever happens for files that are definitely going to be accepted.
 */
class MediaInspector
{
    /**
     * Decoding an image costs roughly 4 bytes of memory per pixel, so very
     * large images are catalogued but not thumbnailed locally.
     */
    public const MAX_DECODE_PIXELS = 60_000_000;

    /**
     * @throws UnsupportedMediaException
     */
    public function inspect(string $path, ?string $clientMimeType = null): MediaAnalysis
    {
        if (! is_readable($path)) {
            throw UnsupportedMediaException::unreadable();
        }

        $size = filesize($path);

        if ($size === false || $size === 0) {
            throw UnsupportedMediaException::unreadable();
        }

        $mimeType = $this->detectMimeType($path);

        if ($mimeType === null) {
            throw UnsupportedMediaException::type($clientMimeType ?? 'unknown');
        }

        $allowed = config('memories.uploads.mime_types');

        if (! isset($allowed[$mimeType])) {
            throw UnsupportedMediaException::type($mimeType);
        }

        $type = $allowed[$mimeType]['type'];
        $extension = $allowed[$mimeType]['ext'];

        $limit = (int) config("memories.uploads.max_bytes.{$type}");

        if ($size > $limit) {
            throw UnsupportedMediaException::tooLarge($type, $size, $limit);
        }

        [$width, $height] = $this->dimensions($path, $type);

        return new MediaAnalysis(
            mimeType: $mimeType,
            type: $type,
            extension: $extension,
            size: $size,
            // hash_file streams the file; it never holds more than a block.
            checksum: (string) hash_file('sha256', $path),
            width: $width,
            height: $height,
            durationMs: $type === 'video' ? $this->videoDuration($path) : null,
            placeholder: $type === 'image' ? $this->placeholder($path, $width, $height) : null,
        );
    }

    /**
     * The MIME type according to the file's own bytes. The browser's
     * Content-Type is never consulted — it is trivially forged.
     */
    public function detectMimeType(string $path): ?string
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
            return $detected;
        }

        // Older libmagic builds do not recognise ISO base media files, which
        // covers most of what phones produce. Read the brand ourselves.
        return $this->sniffIsoBaseMediaBrand($path);
    }

    /**
     * MP4, MOV and HEIC all share the ISO base media container: bytes 4–8 are
     * the literal "ftyp", followed by a four-character brand.
     */
    private function sniffIsoBaseMediaBrand(string $path): ?string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $header = fread($handle, 12);
        } finally {
            fclose($handle);
        }

        if (! is_string($header) || strlen($header) < 12 || substr($header, 4, 4) !== 'ftyp') {
            return null;
        }

        return match (substr($header, 8, 4)) {
            'heic', 'heix', 'hevc', 'hevx', 'heim', 'heis' => 'image/heic',
            'mif1', 'msf1' => 'image/heif',
            'qt  ' => 'video/quicktime',
            'isom', 'iso2', 'mp41', 'mp42', 'avc1', 'mmp4', 'M4V ' => 'video/mp4',
            default => null,
        };
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(string $path, string $type): array
    {
        if ($type !== 'image') {
            return [null, null];
        }

        $info = @getimagesize($path);

        if ($info === false) {
            // HEIC and friends: Drive still stores them, and Drive's own
            // metadata fills the gap later.
            return [null, null];
        }

        [$width, $height] = $info;

        // A portrait photo is stored landscape with an orientation flag; the
        // timeline needs the dimensions as the photo is meant to be seen.
        if ($this->isRotatedQuarterTurn($path)) {
            [$width, $height] = [$height, $width];
        }

        return [(int) $width, (int) $height];
    }

    private function isRotatedQuarterTurn(string $path): bool
    {
        if (! function_exists('exif_read_data')) {
            return false;
        }

        try {
            $exif = @exif_read_data($path);
        } catch (Throwable) {
            return false;
        }

        return is_array($exif) && in_array((int) ($exif['Orientation'] ?? 0), [5, 6, 7, 8], true);
    }

    /**
     * A postage-stamp version of the image, inlined into API responses as a
     * data URI so the timeline can show something the instant it renders.
     */
    private function placeholder(string $path, ?int $width, ?int $height): ?string
    {
        if ($width === null || $height === null) {
            return null;
        }

        if ($width * $height > self::MAX_DECODE_PIXELS) {
            return null;
        }

        try {
            $image = ImageEditor::open($path);

            if ($image === null) {
                return null;
            }

            $target = (int) config('memories.derivatives.placeholder_width', 20);
            $thumb = ImageEditor::resizeToWidth($image, $target);
            unset($image);

            $bytes = ImageEditor::toJpeg($thumb, 45);
            unset($thumb);

            return 'data:image/jpeg;base64,'.base64_encode($bytes);
        } catch (Throwable $e) {
            Log::warning('Could not build a placeholder image.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Duration via ffprobe when the host happens to have it. Absent that,
     * Drive reports it once it has finished processing the video.
     */
    private function videoDuration(string $path): ?int
    {
        $ffprobe = trim((string) @shell_exec('command -v ffprobe 2>/dev/null'));

        if ($ffprobe === '') {
            return null;
        }

        $output = @shell_exec(sprintf(
            '%s -v error -show_entries format=duration -of csv=p=0 %s 2>/dev/null',
            escapeshellcmd($ffprobe),
            escapeshellarg($path),
        ));

        if (! is_string($output) || trim($output) === '') {
            return null;
        }

        $seconds = (float) trim($output);

        return $seconds > 0 ? (int) round($seconds * 1000) : null;
    }
}
