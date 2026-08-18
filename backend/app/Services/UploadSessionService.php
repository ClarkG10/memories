<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UploadSession;
use App\Models\User;
use App\Services\Media\MediaInspector;
use App\Services\Media\PosterFrame;
use App\Services\Media\UnsupportedMediaException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Carries a file from the browser to the server in pieces.
 *
 * A phone video can be gigabytes. Posting that as one request means a single
 * timeout throws the whole transfer away, and it runs headlong into every body
 * size limit between the browser and PHP. Instead the browser opens a session,
 * sends fixed-size chunks that are written straight to their final offset in a
 * sparse file, and asks the server to finish. A dropped connection costs one
 * chunk, and resuming is a matter of re-sending the ones that are missing.
 */
class UploadSessionService
{
    public function __construct(
        private readonly MediaInspector $inspector,
        private readonly UploadCapacity $capacity,
    ) {}

    /**
     * Reserve space for a file that is about to arrive.
     */
    public function open(User $user, string $originalName, int $size, ?string $clientMimeType): UploadSession
    {
        $ceiling = max(
            (int) config('memories.uploads.max_bytes.image'),
            (int) config('memories.uploads.max_bytes.video'),
        );

        if ($size <= 0 || $size > $ceiling) {
            throw ValidationException::withMessages([
                'size' => [sprintf(
                    'That file is %s, and the most this archive accepts is %s.',
                    UploadCapacity::human(max(0, $size)),
                    UploadCapacity::human($ceiling),
                )],
            ]);
        }

        /*
         | The stated maximum is one thing; whether the bytes will physically
         | fit is another. Asked here, before the browser sends the first
         | chunk, because finding out at the end of a two-gigabyte video is
         | the cruellest possible moment to be told there was never any room.
         */
        $this->capacity->guard($size);

        $chunkSize = (int) config('memories.uploads.chunk_bytes');

        /*
         | The row is created first so that the model assigns its own uuid.
         | Deriving the storage path from anything else risks the two drifting
         | apart, and then cleanup deletes a directory that was never there
         | while the real temp file stays on disk forever.
         */
        $session = UploadSession::create([
            'user_id' => $user->id,
            'original_name' => $originalName,
            'client_mime_type' => $clientMimeType,
            'size' => $size,
            'chunk_size' => $chunkSize,
            'total_chunks' => (int) max(1, ceil($size / $chunkSize)),
            'received_chunks' => 0,
            'chunk_map' => [],
            'path' => '',
            'status' => UploadSession::STATUS_PENDING,
            'expires_at' => now()->addMinutes((int) config('memories.uploads.session_ttl_minutes')),
        ]);

        $session->forceFill(['path' => "{$session->uuid}/original"])->save();

        // Create the destination up front so chunk writes only ever open an
        // existing file, whatever order they arrive in.
        $this->disk()->put($session->path, '');

        return $session;
    }

    /**
     * Write one chunk at its offset.
     *
     * @param  resource  $stream
     */
    public function storeChunk(UploadSession $session, int $index, $stream): UploadSession
    {
        if ($session->status !== UploadSession::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'upload' => ['This upload is no longer accepting data.'],
            ]);
        }

        if ($session->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'upload' => ['This upload took too long and was cleaned up. Please try again.'],
            ]);
        }

        if ($index < 0 || $index >= $session->total_chunks) {
            throw ValidationException::withMessages([
                'index' => ["Chunk {$index} is outside this upload."],
            ]);
        }

        $offset = $index * $session->chunk_size;
        $expected = (int) min($session->chunk_size, $session->size - $offset);

        $absolute = $this->disk()->path($session->path);
        $handle = fopen($absolute, 'c+b');

        if ($handle === false) {
            throw new RuntimeException("Could not open upload buffer for session {$session->uuid}.");
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                throw new RuntimeException('Could not seek to the chunk offset.');
            }

            // Copy at most one chunk's worth, so an oversized body cannot
            // overwrite the neighbouring chunk.
            $written = stream_copy_to_stream($stream, $handle, $expected);
        } finally {
            fclose($handle);
        }

        if ($written !== $expected) {
            throw ValidationException::withMessages([
                'chunk' => ["That chunk arrived incomplete ({$written} of {$expected} bytes). Please send it again."],
            ]);
        }

        /*
         | Chunks can be in flight concurrently, and each one has to add itself
         | to the same list. Read-modify-write under a row lock keeps a fast
         | chunk from erasing a slow one's bookkeeping.
         */
        return DB::transaction(function () use ($session, $index): UploadSession {
            /** @var UploadSession $fresh */
            $fresh = UploadSession::query()->lockForUpdate()->findOrFail($session->id);

            $map = $fresh->chunk_map ?? [];

            if (! in_array($index, $map, true)) {
                $map[] = $index;
                sort($map);

                $fresh->forceFill([
                    'chunk_map' => $map,
                    'received_chunks' => count($map),
                ])->save();
            }

            return $fresh;
        });
    }

    /**
     * Seal the session: everything arrived, and the bytes are what they claim.
     *
     * @throws UnsupportedMediaException
     */
    public function complete(UploadSession $session, ?string $posterDataUri = null): UploadSession
    {
        if ($session->isReady()) {
            return $session;
        }

        $missing = $session->missingChunks();

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'upload' => [sprintf(
                    'This upload is still missing %d of %d pieces.',
                    count($missing),
                    $session->total_chunks,
                )],
            ]);
        }

        $absolute = $this->disk()->path($session->path);
        $actual = @filesize($absolute);

        if ($actual !== $session->size) {
            $this->fail($session, "Assembled size {$actual} did not match the declared size {$session->size}.");

            throw new UnsupportedMediaException(
                "The upload didn't arrive intact. Please try again."
            );
        }

        try {
            $analysis = $this->inspector->inspect($absolute, $session->client_mime_type);
        } catch (UnsupportedMediaException $e) {
            $this->fail($session, $e->getMessage());

            throw $e;
        }

        /*
         | A video has no placeholder of its own — this server cannot decode
         | one — so the browser sends a frame it captured while previewing the
         | file. Validated and re-encoded before it is trusted with anything.
         */
        $placeholder = $analysis->placeholder;

        if ($analysis->isVideo()) {
            $poster = PosterFrame::fromDataUri($posterDataUri);

            if ($poster !== null) {
                $this->disk()->put($this->posterPath($session), $poster);
                $placeholder = PosterFrame::placeholder($poster);
            }
        }

        $session->forceFill([
            'mime_type' => $analysis->mimeType,
            'type' => $analysis->type,
            'checksum' => $analysis->checksum,
            // Kept so the memory can be created without examining the file a
            // second time.
            'width' => $analysis->width,
            'height' => $analysis->height,
            'duration_ms' => $analysis->durationMs,
            'placeholder' => $placeholder,
            'status' => UploadSession::STATUS_READY,
        ])->save();

        Log::info('Upload session ready.', [
            'session_uuid' => $session->uuid,
            'type' => $analysis->type,
            'bytes' => $analysis->size,
        ]);

        return $session;
    }

    /**
     * Where a captured video frame waits until a memory claims the upload.
     */
    public function posterPath(UploadSession $session): string
    {
        return "{$session->uuid}/poster.jpg";
    }

    public function posterBytes(UploadSession $session): ?string
    {
        $path = $this->posterPath($session);

        return $this->disk()->exists($path) ? $this->disk()->get($path) : null;
    }

    public function absolutePath(UploadSession $session): string
    {
        return $this->disk()->path($session->path);
    }

    /**
     * Mark a session as spent and drop its temp file. Called once its bytes
     * are safely in Drive.
     */
    public function consume(UploadSession $session): void
    {
        $session->forceFill(['status' => UploadSession::STATUS_CONSUMED])->save();

        $this->discardFile($session);
    }

    public function fail(UploadSession $session, string $reason): void
    {
        $session->forceFill([
            'status' => UploadSession::STATUS_FAILED,
            'error' => $reason,
        ])->save();

        Log::warning('Upload session failed.', [
            'session_uuid' => $session->uuid,
            'reason' => $reason,
        ]);
    }

    public function discardFile(UploadSession $session): void
    {
        $this->disk()->deleteDirectory($session->uuid);
    }

    /**
     * Sweep sessions that were abandoned mid-upload.
     */
    public function pruneExpired(): int
    {
        $pruned = 0;

        UploadSession::query()->reclaimable()->chunkById(100, function ($sessions) use (&$pruned): void {
            foreach ($sessions as $session) {
                $this->discardFile($session);
                $session->forceFill(['status' => UploadSession::STATUS_EXPIRED])->save();
                $pruned++;
            }
        });

        return $pruned;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('uploads');
    }
}
