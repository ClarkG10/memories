<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Memory;
use App\Models\MemoryMedia;
use App\Models\UploadSession;
use App\Services\GoogleDrive\GoogleDriveService;
use App\Services\MemoryService;
use App\Services\TimelineQuery;
use Illuminate\Console\Command;
use Throwable;

/**
 * A health check for the things that go wrong quietly.
 *
 * Orphans, stuck deletions and a disconnected Drive all look fine from the
 * timeline. This is where they become visible.
 */
class DoctorCommand extends Command
{
    protected $signature = 'memories:doctor';

    protected $description = 'Report on storage connectivity and anything left in an inconsistent state';

    public function handle(GoogleDriveService $drive, MemoryService $memories): int
    {
        $problems = 0;

        $this->components->info('Archive');
        $this->line('  Memories: '.Memory::query()->count());
        $this->line('  Media: '.MemoryMedia::query()->where('deletion_state', MemoryMedia::DELETION_ACTIVE)->count());
        $this->line('  Removed, awaiting Drive: '.MemoryMedia::withTrashed()
            ->whereIn('deletion_state', [MemoryMedia::DELETION_DELETING, MemoryMedia::DELETION_FAILED])
            ->count());

        /*
         | The years, read straight from the database rather than through the
         | cache the API answers from. "A year is missing from the web app" has
         | two very different causes — it is not in the database, or it is and
         | the cached answer predates it — and they are told apart here.
         */
        $years = Memory::query()
            ->selectRaw('YEAR(memory_date) as year, COUNT(*) as total')
            ->groupBy('year')
            ->orderByDesc('year')
            ->get();

        if ($years->isNotEmpty()) {
            $this->line('  Years (from the database):');

            foreach ($years as $row) {
                $this->line(sprintf('    %d  %d memor%s', (int) $row->year, (int) $row->total, (int) $row->total === 1 ? 'y' : 'ies'));
            }

            $cached = collect($this->laravel->make(TimelineQuery::class)->years())
                ->pluck('year')
                ->all();

            $missing = $years->pluck('year')->map(fn ($y): int => (int) $y)->diff($cached)->all();

            if ($missing !== []) {
                $problems++;
                $this->components->warn(
                    '  The API is serving a stale year list; missing: '.implode(', ', $missing)
                    .'. Run `php artisan memories:refresh`.'
                );
            }
        }

        $this->newLine();
        $this->components->info('Google Drive');

        if (! $drive->isConfigured()) {
            $this->components->error('  Not connected. Run `php artisan drive:authorize`.');
            $problems++;
        } else {
            try {
                $about = $drive->about();
                $used = $about['usage'] !== null ? $this->bytes($about['usage']) : 'unknown';
                $limit = $about['limit'] !== null ? $this->bytes($about['limit']) : 'unlimited';

                $this->line("  Account: {$about['email']}");
                $this->line("  Storage: {$used} of {$limit}");

                if ($about['limit'] !== null && $about['usage'] !== null && $about['usage'] / $about['limit'] > 0.9) {
                    $this->components->warn('  Drive is over 90% full; uploads will start failing.');
                    $problems++;
                }
            } catch (Throwable $e) {
                $this->components->error('  Could not reach Drive: '.$e->getMessage());
                $problems++;
            }
        }

        $this->newLine();
        $this->components->info('Consistency');

        $abandoned = $memories->abandonedDeletions();

        if ($abandoned->isNotEmpty()) {
            $this->components->warn("  {$abandoned->count()} file(s) could not be deleted from Drive:");

            foreach ($abandoned as $media) {
                $this->line("    {$media->drive_file_id} — {$media->deletion_error}");
            }

            $problems++;
        } else {
            $this->line('  No stuck deletions.');
        }

        $stalled = UploadSession::query()->reclaimable()->count();

        if ($stalled > 0) {
            $this->components->warn("  {$stalled} abandoned upload(s) waiting on `uploads:prune`.");
        } else {
            $this->line('  No abandoned uploads.');
        }

        /*
         | withTrashed matters: a memory removed from the timeline is
         | soft-deleted, and without it every normal deletion would be counted
         | here as an orphan — reporting phantom problems and exiting non-zero
         | during ordinary use, which is how a health check gets ignored.
         */
        $mediaWithoutMemory = MemoryMedia::query()
            ->where('deletion_state', MemoryMedia::DELETION_ACTIVE)
            ->whereDoesntHave('memory', fn ($query) => $query->withTrashed())
            ->count();

        if ($mediaWithoutMemory > 0) {
            $this->components->warn("  {$mediaWithoutMemory} media row(s) have no memory.");
            $problems++;
        }

        $this->newLine();

        if ($problems === 0) {
            $this->components->info('Everything looks healthy.');
        }

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}
