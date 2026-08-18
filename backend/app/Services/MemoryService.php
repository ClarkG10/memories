<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\MemoryUploadException;
use App\Jobs\DeleteDriveFile;
use App\Models\Memory;
use App\Models\MemoryMedia;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\GoogleDrive\DriveFile;
use App\Services\GoogleDrive\GoogleDriveException;
use App\Services\GoogleDrive\GoogleDriveService;
use App\Services\Media\DerivativeService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything that happens to a memory over its life.
 *
 * The ordering here is the important part. Bytes reach Drive *before* any row
 * is written, so the archive never shows a memory whose media does not exist.
 * If a file fails halfway through a batch, the ones already uploaded are
 * removed again rather than left behind as orphans nobody knows about.
 */
class MemoryService
{
    public function __construct(
        private readonly GoogleDriveService $drive,
        private readonly UploadSessionService $uploads,
        private readonly DerivativeService $derivatives,
        private readonly MemoryCache $cache,
    ) {}

    /**
     * @param  array{title: string, description?: string|null, memory_date: string, location?: string|null}  $attributes
     * @param  array<int, string>  $sessionUuids  in the order they should appear
     *
     * @throws MemoryUploadException
     */
    public function create(User $user, array $attributes, array $sessionUuids): Memory
    {
        $sessions = $this->readySessions($user, $sessionUuids);
        $date = Carbon::parse($attributes['memory_date']);

        $uploaded = $this->uploadAll($sessions, $attributes['title'], $date, $attributes['album'] ?? null);

        $memory = $this->recordOrUndo($uploaded, function () use ($user, $attributes, $uploaded): Memory {
            $memory = new Memory($attributes);
            $memory->user_id = $user->id;
            $memory->media_count = count($uploaded);
            $memory->save();

            $this->persistMedia($memory, $uploaded, startingAt: 0);

            return $memory;
        });

        foreach ($sessions as $session) {
            $this->uploads->consume($session);
        }

        $this->cache->flush();

        Log::info('Memory created.', [
            'memory_uuid' => $memory->uuid,
            'media' => count($uploaded),
        ]);

        return $memory->load('media');
    }

    /**
     * Add more media to a memory that already exists.
     *
     * @param  array<int, string>  $sessionUuids
     *
     * @throws MemoryUploadException
     */
    public function attachMedia(Memory $memory, User $user, array $sessionUuids): Memory
    {
        $sessions = $this->readySessions($user, $sessionUuids, $memory->media_count, $memory);
        $uploaded = $this->uploadAll($sessions, $memory->title, $memory->memory_date, $memory->album);

        $this->recordOrUndo($uploaded, function () use ($memory, $uploaded): Memory {
            $next = (int) $memory->media()->max('sort_order') + 1;

            $this->persistMedia($memory, $uploaded, startingAt: $next);

            $memory->forceFill(['media_count' => $memory->media()->count()])->save();

            return $memory;
        });

        foreach ($sessions as $session) {
            $this->uploads->consume($session);
        }

        $this->cache->flush();

        return $memory->fresh(['media']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Memory $memory, array $attributes): Memory
    {
        $wasFiledUnder = [$memory->album, $memory->memory_date->toDateString()];

        $memory->fill($attributes)->save();

        $this->cache->flush();

        // Where a file lives in Drive is derived from the album and the date,
        // so changing either has to move the files to match. Otherwise the
        // archive says one thing and the folder tree says another.
        if ($wasFiledUnder !== [$memory->album, $memory->memory_date->toDateString()]) {
            $this->refile($memory);
        }

        Log::info('Memory updated.', ['memory_uuid' => $memory->uuid]);

        return $memory->fresh(['media']);
    }

    /**
     * Put this memory's files where its album and date now say they belong.
     *
     * Best effort by design. Nothing in the archive depends on a file's folder
     * — every lookup is by Drive id — so a move that fails is untidy, never
     * lost, and must not turn a successful edit into an error for the person
     * who made it.
     */
    private function refile(Memory $memory): void
    {
        foreach ($memory->media()->where('deletion_state', MemoryMedia::DELETION_ACTIVE)->get() as $media) {
            try {
                $target = $this->drive->folderForMedia(
                    (string) $media->type,
                    $memory->memory_date,
                    $memory->album,
                );

                if ($target === $media->drive_folder_id) {
                    continue;
                }

                $this->drive->moveFile($media->drive_file_id, $target);

                $media->forceFill(['drive_folder_id' => $target])->save();
            } catch (Throwable $e) {
                Log::warning('Could not refile a memory in Drive; the file stays where it was.', [
                    'media_uuid' => $media->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Take a memory out of the timeline and start removing its files.
     *
     * The row disappears from view immediately and the Drive deletions happen
     * on the queue, because a slow or failing third-party API must never be
     * the reason someone is still looking at a memory they asked to remove.
     */
    public function delete(Memory $memory): void
    {
        $mediaIds = DB::transaction(function () use ($memory): array {
            $media = $memory->media()->where('deletion_state', MemoryMedia::DELETION_ACTIVE)->get();

            foreach ($media as $item) {
                $item->forceFill([
                    'deletion_state' => MemoryMedia::DELETION_DELETING,
                    'deletion_requested_at' => now(),
                    'deletion_error' => null,
                ])->save();
            }

            $memory->delete();

            return $media->pluck('id')->all();
        });

        $this->cache->flush();

        Log::info('Memory removed from the timeline.', [
            'memory_uuid' => $memory->uuid,
            'media_pending_deletion' => count($mediaIds),
        ]);

        $this->queueDriveDeletions($mediaIds);
    }

    /**
     * Remove a single file from a memory that otherwise stays.
     */
    public function deleteMedia(MemoryMedia $media): void
    {
        DB::transaction(function () use ($media): void {
            $media->forceFill([
                'deletion_state' => MemoryMedia::DELETION_DELETING,
                'deletion_requested_at' => now(),
                'deletion_error' => null,
            ])->save();

            $media->delete();

            $memory = $media->memory;

            if ($memory !== null) {
                $memory->forceFill(['media_count' => $memory->media()->count()])->save();
            }
        });

        $this->cache->flush();

        $this->queueDriveDeletions([$media->id]);
    }

    /**
     * Hand the Drive deletions to the queue.
     *
     * Whatever happens here, the memory is already out of the timeline and the
     * media rows record that removal was requested. So a queue that is down —
     * or configured to run jobs inline, where a Drive outage would surface as
     * a failed request — must not turn a successful removal into an error for
     * the person who asked for it. The hourly sweep picks up anything missed.
     *
     * @param  array<int, int>  $mediaIds
     */
    private function queueDriveDeletions(array $mediaIds): void
    {
        foreach ($mediaIds as $id) {
            try {
                DeleteDriveFile::dispatch($id);
            } catch (Throwable $e) {
                Log::error('Could not queue a Drive deletion; the sweep will retry it.', [
                    'media_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Ask Drive to forget a file, and record honestly what happened.
     *
     * Returns false when the file is still there and the attempt should be
     * repeated — the catalogue keeps pointing at it until it is really gone.
     */
    public function purgeMediaFromDrive(MemoryMedia $media): bool
    {
        if ($media->deletion_state === MemoryMedia::DELETION_DELETED) {
            return true;
        }

        try {
            $this->drive->deleteFile($media->drive_file_id);
        } catch (GoogleDriveException $e) {
            /*
             | The attempt budget is for problems that will not fix themselves.
             | A rate limit or a Drive outage is transient, and spending the
             | budget on those means an hour of Drive being unreachable
             | permanently abandons every pending deletion — leaving files in
             | Drive that someone asked to have removed.
             */
            $media->forceFill([
                'deletion_state' => MemoryMedia::DELETION_FAILED,
                'deletion_error' => Str::limit($e->getMessage(), 900),
                'deletion_attempts' => $e->isRetryable()
                    ? $media->deletion_attempts
                    : $media->deletion_attempts + 1,
            ])->save();

            Log::error('Drive deletion failed.', [
                'media_uuid' => $media->uuid,
                'drive_file_id' => $media->drive_file_id,
                'attempts' => $media->deletion_attempts,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $this->derivatives->forget($media);

        $media->forceFill([
            'deletion_state' => MemoryMedia::DELETION_DELETED,
            'deletion_error' => null,
            'deletion_attempts' => $media->deletion_attempts + 1,
        ])->save();

        if ($media->deleted_at === null) {
            $media->delete();
        }

        return true;
    }

    /**
     * Re-queue everything that failed to delete. Run by the scheduler, and
     * available by hand as `php artisan memories:retry-deletions`.
     */
    public function retryFailedDeletions(): int
    {
        $queued = 0;

        MemoryMedia::query()
            ->withTrashed()
            ->awaitingDeletion()
            ->chunkById(100, function (Collection $media) use (&$queued): void {
                foreach ($media as $item) {
                    DeleteDriveFile::dispatch($item->id);
                    $queued++;
                }
            });

        return $queued;
    }

    /**
     * Files this archive has given up on automatically. Surfaced by
     * `memories:doctor` so they are never silently forgotten.
     *
     * @return Collection<int, MemoryMedia>
     */
    public function abandonedDeletions(): Collection
    {
        return MemoryMedia::query()
            ->withTrashed()
            ->where('deletion_state', MemoryMedia::DELETION_FAILED)
            ->where('deletion_attempts', '>=', MemoryMedia::MAX_DELETION_ATTEMPTS)
            ->get();
    }

    /**
     * Resolve the sessions for this request, rejecting anything that is not
     * the caller's, not finished, or already spent.
     *
     * @param  array<int, string>  $uuids
     * @return Collection<int, UploadSession>
     */
    private function readySessions(User $user, array $uuids, int $existing = 0, ?Memory $memory = null): Collection
    {
        $uuids = array_values(array_unique($uuids));

        if ($uuids === []) {
            throw new MemoryUploadException('A memory needs at least one photo or video.', retryable: false);
        }

        $max = (int) config('memories.uploads.max_files_per_memory');

        if (count($uuids) + $existing > $max) {
            throw new MemoryUploadException(
                "A single memory can hold up to {$max} photos and videos.",
                retryable: false,
            );
        }

        $sessions = UploadSession::query()
            ->where('user_id', $user->id)
            ->whereIn('uuid', $uuids)
            ->get()
            ->keyBy('uuid');

        $ordered = new Collection;

        foreach ($uuids as $uuid) {
            $session = $sessions->get($uuid);

            if ($session === null || ! $session->isReady()) {
                throw new MemoryUploadException(
                    'Some of those files finished uploading incorrectly. Please add them again.',
                    retryable: false,
                );
            }

            $ordered->push($session);
        }

        // The same bytes twice in one memory is never what someone means, and
        // the per-memory checksum index would reject it as a database error
        // rather than as something explainable.
        $deduped = $ordered->unique('checksum')->values();

        if ($memory !== null) {
            /*
             | Includes media already removed from this memory. The unique
             | index on (memory_id, checksum) still counts the tombstone, so
             | re-adding a photo that was taken out would fail as a database
             | error rather than as something explainable.
             */
            $alreadyHere = $memory->media()->withTrashed()->pluck('checksum')->filter()->all();

            $deduped = $deduped
                ->reject(fn (UploadSession $session): bool => in_array($session->checksum, $alreadyHere, true))
                ->values();

            if ($deduped->isEmpty()) {
                throw new MemoryUploadException(
                    'Those files are already part of this memory, or were removed from it.',
                    retryable: false,
                );
            }
        }

        return $deduped;
    }

    /**
     * Push every file to Drive, cleaning up after ourselves if one fails.
     *
     * @param  Collection<int, UploadSession>  $sessions
     * @return array<int, array{session: UploadSession, file: DriveFile, folder: string}>
     *
     * @throws MemoryUploadException
     */
    private function uploadAll(
        Collection $sessions,
        string $title,
        CarbonInterface $date,
        ?string $album = null,
    ): array {
        $uploaded = [];

        try {
            foreach ($sessions as $index => $session) {
                // The memory's own date decides the folder — not today, and
                // not whatever the camera stamped into the file. An album, if
                // one was named, overrides that entirely.
                $folderId = $this->drive->folderForMedia((string) $session->type, $date, $album);

                $file = $this->drive->uploadFile(
                    localPath: $this->uploads->absolutePath($session),
                    name: $this->driveFileName($title, $date, $session, $index),
                    mimeType: (string) $session->mime_type,
                    folderId: $folderId,
                );

                $uploaded[] = ['session' => $session, 'file' => $file, 'folder' => $folderId];
            }
        } catch (GoogleDriveException $e) {
            $this->rollBackDriveUploads($uploaded);

            throw MemoryUploadException::fromDrive($e);
        } catch (Throwable $e) {
            $this->rollBackDriveUploads($uploaded);

            Log::error('Unexpected failure while uploading a memory.', ['error' => $e->getMessage()]);

            throw new MemoryUploadException(
                "We couldn't finish uploading your memory. It hasn't been added yet — please try again.",
                retryable: true,
                previous: $e,
            );
        }

        return $uploaded;
    }

    /**
     * Write the catalogue rows, and take the files back out of Drive if that
     * write does not go through.
     *
     * Without this, a constraint violation or a dropped database connection
     * would leave the bytes sitting in Drive with nothing in the archive
     * pointing at them — invisible, unreferenced, and counting against the
     * owner's storage forever.
     *
     * @param  array<int, array{session: UploadSession, file: DriveFile, folder: string}>  $uploaded
     * @param  callable(): Memory  $write
     *
     * @throws MemoryUploadException
     */
    private function recordOrUndo(array $uploaded, callable $write): Memory
    {
        try {
            return DB::transaction($write);
        } catch (Throwable $e) {
            Log::error('Could not record a memory after its files reached Drive.', [
                'error' => $e->getMessage(),
            ]);

            $this->rollBackDriveUploads($uploaded);

            throw new MemoryUploadException(
                "We couldn't finish saving your memory. It hasn't been added — please try again.",
                retryable: true,
                previous: $e,
            );
        }
    }

    /**
     * @param  array<int, array{session: UploadSession, file: DriveFile, folder: string}>  $uploaded
     */
    private function rollBackDriveUploads(array $uploaded): void
    {
        foreach ($uploaded as $entry) {
            try {
                $this->drive->deleteFile($entry['file']->id);
            } catch (Throwable $e) {
                // The memory is being abandoned either way; record the stray
                // file so `memories:doctor` can report it.
                Log::error('Could not clean up a Drive file after a failed upload.', [
                    'drive_file_id' => $entry['file']->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{session: UploadSession, file: DriveFile, folder: string}>  $uploaded
     */
    private function persistMedia(Memory $memory, array $uploaded, int $startingAt): void
    {
        foreach ($uploaded as $offset => $entry) {
            $session = $entry['session'];
            $file = $entry['file'];

            $media = $memory->media()->create([
                'type' => (string) $session->type,
                'drive_file_id' => $file->id,
                'drive_folder_id' => $entry['folder'],
                'drive_web_view_url' => $file->webViewLink,
                'drive_thumbnail_url' => $file->thumbnailLink,
                'file_name' => $file->name,
                'original_name' => $session->original_name,
                'mime_type' => (string) $session->mime_type,
                'file_size' => $session->size,
                // Prefer what was measured locally; fall back to Drive's own
                // metadata, which is all we get for formats GD cannot read
                // and for videos this server has no ffprobe to inspect.
                'width' => $session->width ?? $file->width,
                'height' => $session->height ?? $file->height,
                'duration_ms' => $session->duration_ms ?? $file->durationMs,
                'checksum' => $session->checksum,
                'placeholder' => $session->placeholder,
                'sort_order' => $startingAt + $offset,
                'deletion_state' => MemoryMedia::DELETION_ACTIVE,
            ]);

            /*
             | Hand the browser-captured frame to the derivative cache, where
             | the media proxy already looks for a poster. That makes a video
             | show a real still the moment it is saved, rather than waiting
             | for Drive to finish generating one of its own.
             */
            $poster = $this->uploads->posterBytes($session);

            if ($poster !== null) {
                $this->derivatives->storePoster($media, $poster);
            }
        }
    }

    /**
     * A name that makes sense to someone browsing Drive directly:
     *
     *   2026-08-10 That Beautiful Evening 01.jpg
     */
    private function driveFileName(string $title, CarbonInterface $date, UploadSession $session, int $index): string
    {
        $slug = Str::limit(
            trim(preg_replace('/[^\p{L}\p{N} _-]+/u', '', $title) ?? '') ?: 'Memory',
            60,
            '',
        );

        // The extension comes from the detected MIME type, never from the
        // uploaded filename, which the client controls.
        return sprintf(
            '%s %s %02d.%s',
            $date->format('Y-m-d'),
            $slug,
            $index + 1,
            $this->extensionFor($session),
        );
    }

    private function extensionFor(UploadSession $session): string
    {
        $types = (array) config('memories.uploads.mime_types');

        return $types[$session->mime_type]['ext'] ?? 'bin';
    }
}
