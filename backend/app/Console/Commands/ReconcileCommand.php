<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Memory;
use App\Models\MemoryMedia;
use App\Services\GoogleDrive\GoogleDriveException;
use App\Services\GoogleDrive\GoogleDriveService;
use Illuminate\Console\Command;

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

    public function handle(GoogleDriveService $drive): int
    {
        if (! $drive->isConfigured()) {
            $this->components->error('Drive is not connected. Run `php artisan drive:authorize`.');

            return self::FAILURE;
        }

        $this->components->info('Reading Drive…');

        try {
            $files = $drive->listOwnFiles();
        } catch (GoogleDriveException $e) {
            $this->components->error('Could not read Drive: '.$e->getMessage());

            return self::FAILURE;
        }

        /*
         | withTrashed on both sides. A soft-deleted memory still legitimately
         | owns its files until the queue collects them, and counting those as
         | orphans would report a problem during entirely ordinary use.
         */
        $known = MemoryMedia::withTrashed()->pluck('deletion_state', 'drive_file_id');

        $year = $this->option('year');
        $orphans = [];
        $orphanBytes = 0;

        foreach ($files as $file) {
            if ($known->has($file['id'])) {
                continue;
            }

            // The name carries the date it was filed under: "2025-11-23 Title 01.jpg".
            if ($year !== null && ! str_starts_with($file['name'], (string) $year)) {
                continue;
            }

            $orphans[] = $file;
            $orphanBytes += $file['size'];
        }

        $this->line('  Files in Drive: '.count($files));
        $this->line('  Claimed by a memory: '.(count($files) - count($orphans)));
        $this->newLine();

        if ($orphans !== []) {
            $this->components->warn(sprintf(
                '%d file(s) in Drive belong to no memory (%s).',
                count($orphans),
                $this->bytes($orphanBytes),
            ));

            $this->line('  Either a save failed after the upload, or a memory was deleted and');
            $this->line('  its removal never ran. They are taking up space and are invisible');
            $this->line('  in the archive. Nothing here deletes them — that is your call.');
            $this->newLine();

            $byFolder = [];

            foreach ($orphans as $file) {
                $byFolder[$file['parent'] ?? 'unknown'][] = $file['name'];
            }

            foreach ($byFolder as $parent => $names) {
                $label = $parent === 'unknown' ? 'unknown folder' : ($drive->folderName($parent) ?? $parent);

                $this->line(sprintf('  %s — %d file(s)', $label, count($names)));

                foreach (array_slice($names, 0, 4) as $name) {
                    $this->line('      '.$name);
                }

                if (count($names) > 4) {
                    $this->line(sprintf('      … and %d more', count($names) - 4));
                }
            }

            $this->newLine();
            $this->line('  If a memory was deleted on purpose, remove them in Drive.');
            $this->line('  If it was not, the memory is gone and only these files remain.');
        } else {
            $this->components->info('Every file in Drive belongs to a memory.');
        }

        /*
         | And the other direction. A memory pointing at a file that is no
         | longer there renders as a broken photograph, and nothing in the
         | interface can say why.
         */
        $present = collect($files)->pluck('id')->flip();

        $missing = MemoryMedia::query()
            ->where('deletion_state', MemoryMedia::DELETION_ACTIVE)
            ->get(['id', 'uuid', 'memory_id', 'drive_file_id', 'original_name'])
            ->reject(fn (MemoryMedia $media): bool => $present->has($media->drive_file_id));

        $this->newLine();

        if ($missing->isNotEmpty()) {
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

            return self::FAILURE;
        }

        $this->components->info('Every photograph the archive holds is still in Drive.');

        return $orphans === [] ? self::SUCCESS : self::FAILURE;
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}
