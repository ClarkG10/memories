<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\GoogleDrive\GoogleDriveException;
use App\Services\GoogleDrive\GoogleDriveService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Whether there is actually room for a file, and how much room there is.
 *
 * The configured maximum is a stated rule; this is the physical truth behind
 * it. A file travels browser → this server's disk → Drive, so it has to fit
 * twice, and either can run out first. Finding that out after an hour of
 * uploading a video is the worst possible moment, so it is checked before the
 * first byte is sent and answered with the real number rather than "failed".
 */
final class UploadCapacity
{
    /**
     * Drive's quota is a network call. Uploading forty files should not make
     * forty of them, and free space does not move quickly enough to matter.
     */
    private const QUOTA_TTL_SECONDS = 60;

    public function __construct(private readonly GoogleDriveService $drive) {}

    /**
     * What is left, as far as anyone can tell. A null means "unknown" — an
     * unlimited Drive plan, or a Drive that could not be reached — and is
     * never treated as "full".
     *
     * @return array{disk_free_bytes: int|null, drive_free_bytes: int|null, drive_total_bytes: int|null, headroom_bytes: int, max_image_bytes: int, max_video_bytes: int}
     */
    public function snapshot(): array
    {
        $quota = $this->driveQuota();

        return [
            'disk_free_bytes' => $this->diskFree(),
            'drive_free_bytes' => $quota['free'],
            'drive_total_bytes' => $quota['limit'],
            'headroom_bytes' => (int) config('memories.uploads.disk_headroom_bytes'),
            'max_image_bytes' => (int) config('memories.uploads.max_bytes.image'),
            'max_video_bytes' => (int) config('memories.uploads.max_bytes.video'),
        ];
    }

    /**
     * Refuse a file that cannot possibly land, before it starts.
     *
     * @throws ValidationException
     */
    public function guard(int $size): void
    {
        $headroom = (int) config('memories.uploads.disk_headroom_bytes');
        $diskFree = $this->diskFree();

        if ($diskFree !== null && $size + $headroom > $diskFree) {
            Log::error('Refused an upload: the server disk would not hold it.', [
                'size' => $size,
                'disk_free' => $diskFree,
                'headroom' => $headroom,
            ]);

            throw ValidationException::withMessages([
                'size' => [sprintf(
                    'There is not enough room on the server for that file right now — %s free, and it needs %s. Try again once some space has been cleared.',
                    self::human(max(0, $diskFree - $headroom)),
                    self::human($size),
                )],
            ]);
        }

        $driveFree = $this->driveQuota()['free'];

        if ($driveFree !== null && $size > $driveFree) {
            Log::warning('Refused an upload: the Drive account has no room for it.', [
                'size' => $size,
                'drive_free' => $driveFree,
            ]);

            throw ValidationException::withMessages([
                'size' => [sprintf(
                    'The connected Google Drive has %s left and that file is %s. Free some space in Drive, or remove a memory you no longer want.',
                    self::human($driveFree),
                    self::human($size),
                )],
            ]);
        }
    }

    /**
     * Free bytes on the disk the temporary file lands on, or null where the
     * platform will not say.
     */
    private function diskFree(): ?int
    {
        $path = storage_path('app');

        if (! is_dir($path)) {
            $path = storage_path();
        }

        $free = @disk_free_space($path);

        return is_float($free) && $free >= 0 ? (int) $free : null;
    }

    /**
     * @return array{free: int|null, limit: int|null}
     */
    private function driveQuota(): array
    {
        /** @var array{free: int|null, limit: int|null} */
        return Cache::remember('drive:quota', self::QUOTA_TTL_SECONDS, function (): array {
            try {
                $about = $this->drive->about();
            } catch (GoogleDriveException $e) {
                /*
                 | Not being able to ask is not the same as there being no
                 | room. Logged, because a Drive that cannot be reached is
                 | about to break far more than one upload.
                 */
                Log::warning('Could not read the Drive storage quota.', [
                    'error' => $e->getMessage(),
                    'status' => $e->status,
                ]);

                return ['free' => null, 'limit' => null];
            }

            $limit = $about['limit'];
            $usage = $about['usage'];

            // A Google Workspace account with pooled or unlimited storage
            // reports no limit at all.
            if ($limit === null || $usage === null) {
                return ['free' => null, 'limit' => $limit];
            }

            return ['free' => max(0, $limit - $usage), 'limit' => $limit];
        });
    }

    public static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $power > 2 ? 1 : 0).' '.$units[$power];
    }
}
