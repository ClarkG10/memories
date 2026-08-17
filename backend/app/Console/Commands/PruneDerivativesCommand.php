<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps the derivative cache under a ceiling.
 *
 * These are resized copies of photographs whose originals are safely in Drive,
 * so anything removed here costs one slower request later and nothing else.
 * Left to itself the directory grows for the life of the archive, and a full
 * disk takes the whole site down — which is a great deal worse than a
 * regenerated thumbnail.
 *
 * Least-recently-modified goes first, which for a timeline read in reverse
 * chronological order means the oldest memories give up their cache before
 * this year's do.
 */
class PruneDerivativesCommand extends Command
{
    protected $signature = 'derivatives:prune
        {--max-bytes= : Override the configured ceiling}
        {--dry-run : Report what would go without removing anything}';

    protected $description = 'Trim the cached image renditions back under their size limit';

    public function handle(): int
    {
        $disk = Storage::disk((string) config('memories.derivatives.disk', 'derivatives'));
        $limit = (int) ($this->option('max-bytes') ?: config('memories.derivatives.max_bytes'));

        if ($limit <= 0) {
            $this->components->info('No ceiling configured; nothing to do.');

            return self::SUCCESS;
        }

        $entries = [];
        $total = 0;

        foreach ($disk->directories() as $directory) {
            $size = 0;
            $touched = 0;

            foreach ($disk->allFiles($directory) as $file) {
                $size += $disk->size($file);
                $touched = max($touched, $disk->lastModified($file));
            }

            if ($size === 0) {
                // An empty directory left behind by a removed memory.
                $disk->deleteDirectory($directory);

                continue;
            }

            $entries[] = ['path' => $directory, 'size' => $size, 'touched' => $touched];
            $total += $size;
        }

        $this->components->info(sprintf(
            'Cache holds %s across %d item(s); ceiling is %s.',
            $this->bytes($total),
            count($entries),
            $this->bytes($limit),
        ));

        if ($total <= $limit) {
            return self::SUCCESS;
        }

        usort($entries, fn (array $a, array $b): int => $a['touched'] <=> $b['touched']);

        $freed = 0;
        $removed = 0;

        foreach ($entries as $entry) {
            if ($total - $freed <= $limit) {
                break;
            }

            if (! $this->option('dry-run')) {
                $disk->deleteDirectory($entry['path']);
            }

            $freed += $entry['size'];
            $removed++;
        }

        $this->components->info(sprintf(
            '%s %s across %d item(s).',
            $this->option('dry-run') ? 'Would free' : 'Freed',
            $this->bytes($freed),
            $removed,
        ));

        return self::SUCCESS;
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}
