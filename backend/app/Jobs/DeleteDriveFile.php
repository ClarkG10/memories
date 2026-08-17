<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MemoryMedia;
use App\Services\MemoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Removes one file from Drive after its memory has already left the timeline.
 *
 * Runs on the queue so that a slow or unavailable Drive never delays the
 * person who pressed remove, and so a failure survives to be retried instead
 * of vanishing with the request.
 */
class DeleteDriveFile implements ShouldQueue
{
    use Queueable;

    /**
     * Attempts here are for infrastructure failures. The count that decides
     * whether to keep trying at all lives on the media row, so it survives
     * across separate dispatches.
     */
    public int $tries = 3;

    public function __construct(public readonly int $mediaId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        /*
         | A retry sweep and the original dispatch can collide; deleting the
         | same file twice is harmless but the bookkeeping should not race.
         |
         | releaseAfter matters as much as the lock. Without it a job that
         | cannot get the lock is put straight back on the queue and picked up
         | again immediately, spending all three attempts within a few
         | milliseconds without ever having reached Drive.
         */
        return [
            (new WithoutOverlapping((string) $this->mediaId))
                ->expireAfter(180)
                ->releaseAfter(60),
        ];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(MemoryService $memories): void
    {
        $media = MemoryMedia::withTrashed()->find($this->mediaId);

        if ($media === null) {
            return;
        }

        if ($media->deletion_state === MemoryMedia::DELETION_ACTIVE) {
            // The memory was restored between dispatch and execution.
            return;
        }

        if ($media->deletion_attempts >= MemoryMedia::MAX_DELETION_ATTEMPTS) {
            Log::error('Giving up on a Drive deletion; it needs a human.', [
                'media_uuid' => $media->uuid,
                'drive_file_id' => $media->drive_file_id,
            ]);

            return;
        }

        if (! $memories->purgeMediaFromDrive($media)) {
            // Failing the job hands the retry timing to the queue's backoff.
            // Once those are spent the scheduled sweep picks it up again, so
            // the file stays accounted for either way.
            throw new RuntimeException(
                "Drive would not delete file {$media->drive_file_id}."
            );
        }
    }
}
