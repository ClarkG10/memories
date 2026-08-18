<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Memory;
use App\Models\MemoryMedia;
use App\Models\UploadSession;
use App\Models\User;
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

        /*
         | Deleted memories are counted separately and shown beside the live
         | ones. "A year is in Google Drive but not in the app" has exactly one
         | remaining explanation once the query and the cache are ruled out —
         | the memory was deleted and its files were never collected — and this
         | is the line that says so instead of leaving it to be deduced.
         */
        $deletedByYear = Memory::onlyTrashed()
            ->selectRaw('YEAR(memory_date) as year, COUNT(*) as total')
            ->groupBy('year')
            ->pluck('total', 'year');

        if ($years->isNotEmpty() || $deletedByYear->isNotEmpty()) {
            $this->line('  Years (from the database):');

            $live = $years->pluck('total', 'year');

            foreach ($live->keys()->merge($deletedByYear->keys())->unique()->sortDesc() as $year) {
                $shown = (int) ($live[$year] ?? 0);
                $gone = (int) ($deletedByYear[$year] ?? 0);

                $this->line(sprintf(
                    '    %d  %d memor%s%s',
                    (int) $year,
                    $shown,
                    $shown === 1 ? 'y' : 'ies',
                    $gone > 0 ? "  ({$gone} deleted, still in Drive until the queue collects them)" : '',
                ));
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

        /*
         | An archive belongs to one person. A second users row appears more
         | easily than it should — `archive:owner` given a different email
         | makes one without comment — and while nothing breaks any more, an
         | archive whose memories are filed under two names is a thing waiting
         | to confuse whoever looks at the database next.
         */
        $owners = User::query()
            ->withCount('memories')
            ->orderBy('id')
            ->get(['id', 'email']);

        $this->newLine();
        $this->components->info('Owner');

        foreach ($owners as $owner) {
            $this->line(sprintf(
                '  %s — %d memor%s',
                $owner->email,
                (int) $owner->memories_count,
                (int) $owner->memories_count === 1 ? 'y' : 'ies',
            ));
        }

        if ($owners->count() > 1) {
            $this->line('  More than one account exists. Signing in as any of them edits everything,');
            $this->line('  so nothing is broken — but one archive under one name is tidier:');
            $this->line('    php artisan memories:reassign --to=you@example.com');
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

        /*
         | Files asked to be removed that never were. A handful is normal for a
         | minute; a pile that does not shrink means nothing is consuming the
         | queue — and that stops far more than deletions. Warming, which is
         | what makes a photograph appear instantly the first time, runs there
         | too and would be silently doing nothing.
         */
        $stuck = MemoryMedia::withTrashed()
            ->where('deletion_state', MemoryMedia::DELETION_DELETING)
            ->where('deletion_requested_at', '<', now()->subMinutes(15))
            ->count();

        if ($stuck > 0) {
            $problems++;
            $this->components->warn(
                "  {$stuck} file(s) have been waiting over 15 minutes to be removed from Drive."
            );
            $this->line('    Nothing is consuming the queue. On Forge: check the queue worker is running.');
            $this->line('    Derivative warming runs there too, so photographs will also be slow to appear.');
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
