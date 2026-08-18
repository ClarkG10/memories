<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Memory;
use App\Models\MemoryMedia;
use App\Services\DriveReconciler;
use App\Services\GoogleDrive\DriveFile;
use App\Services\GoogleDrive\GoogleDriveException;
use App\Services\GoogleDrive\GoogleDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * What is in Drive, against what the archive knows about.
 *
 * These two can disagree in both directions and neither shows up in the
 * interface. A save that died after the upload leaves files nobody claims. A
 * deletion whose queued job never ran leaves the same. And a file removed from
 * Drive by hand leaves a memory pointing at nothing, which renders as a broken
 * photograph with no explanation anywhere.
 *
 * "Google Drive has a folder for 2025 and the archive does not" is exactly
 * this question, and until now the only way to answer it was to guess.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'memories:reconcile {--year= : Only look at files filed under this year}';

    protected $description = 'Compare the files in Google Drive against the memories that claim them';

    public function handle(GoogleDriveService $drive, DriveReconciler $reconciler): int
    {
        if (! $drive->isConfigured()) {
            $this->components->error('Drive is not connected. Run `php artisan drive:authorize`.');

            return self::FAILURE;
        }

        $this->components->info('Reading Drive…');

        $year = $this->option('year') !== null ? (int) $this->option('year') : null;

        try {
            $orphans = $reconciler->orphans($year);
            $missing = $reconciler->missing();
        } catch (GoogleDriveException $e) {
            $this->components->error('Could not read Drive: '.$e->getMessage());

            return self::FAILURE;
        }

        $problems = 0;

        if ($orphans->isNotEmpty()) {
            $problems++;
            $this->reportOrphans($drive, $reconciler, $orphans);
        } else {
            $this->components->info('Every file in Drive belongs to a memory.');
        }

        $this->newLine();

        if ($missing->isNotEmpty()) {
            $problems++;
            $this->reportMissing($missing);
        } else {
            $this->components->info('Every photograph the archive holds is still in Drive.');
        }

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  Collection<int, array{file: DriveFile, parent: string|null}>  $orphans
     */
    private function reportOrphans(
        GoogleDriveService $drive,
        DriveReconciler $reconciler,
        Collection $orphans,
    ): void {
        $bytes = (int) $orphans->sum(fn (array $entry): int => $entry['file']->size ?? 0);

        $this->components->warn(sprintf(
            '%d file(s) in Drive belong to no memory (%s).',
            $orphans->count(),
            $this->bytes($bytes),
        ));

        $this->line('  Either a save failed after the upload, or a memory was deleted and');
        $this->line('  its removal never ran. Nothing here deletes them — which of those');
        $this->line('  two happened decides what should be done, and that is your call.');
        $this->newLine();

        foreach ($reconciler->groupByFolder($orphans) as $parent => $group) {
            $label = $parent === 'unknown' ? 'unknown folder' : ($drive->folderName($parent) ?? $parent);
            $guess = DriveReconciler::readName($group->first()['file']->name);

            $this->line(sprintf('  %s — %d file(s)', $label, $group->count()));

            if ($guess['title'] !== null) {
                $this->line(sprintf('    looks like "%s" from %s', $guess['title'], $guess['date']));
            }

            foreach ($group->take(3) as $entry) {
                $this->line('      '.$entry['file']->name);
            }

            if ($group->count() > 3) {
                $this->line(sprintf('      … and %d more', $group->count() - 3));
            }
        }

        $this->newLine();
        $this->line('  To put a set back into the archive without re-uploading anything:');
        $this->line('    php artisan memories:import');
    }

    /**
     * @param  Collection<int, MemoryMedia>  $missing
     */
    private function reportMissing(Collection $missing): void
    {
        $this->components->warn(
            $missing->count().' photograph(s) point at a Drive file that is not there.'
        );

        $titles = Memory::withTrashed()
            ->whereIn('id', $missing->pluck('memory_id')->unique())
            ->pluck('title', 'id');

        foreach ($missing->take(10) as $media) {
            $this->line(sprintf(
                '    %s — in "%s"',
                $media->original_name,
                $titles[$media->memory_id] ?? 'a deleted memory',
            ));
        }

        $this->newLine();
        $this->line('  These are the ones that show as a broken photograph.');
        $this->line('  Remove them from the memory in the app, or restore them in Drive.');
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}
