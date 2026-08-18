<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\WarmDerivatives;
use App\Models\MemoryMedia;
use Illuminate\Console\Command;

/**
 * Render every photograph's sizes ahead of anyone asking.
 *
 * New memories warm themselves as they are saved. This is for everything that
 * was already in the archive before that was true, and for the first run after
 * the derivative cache has been pruned — otherwise the first person through
 * the timeline pays for rebuilding all of it.
 */
class WarmCommand extends Command
{
    protected $signature = 'memories:warm
        {--now : Render in this process rather than queueing, and show progress}
        {--limit=0 : Stop after this many files}';

    protected $description = 'Pre-render image sizes and video posters so the first view is instant';

    public function handle(): int
    {
        $query = MemoryMedia::query()
            ->where('deletion_state', MemoryMedia::DELETION_ACTIVE)
            ->orderByDesc('id');

        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->components->info('Nothing to warm.');

            return self::SUCCESS;
        }

        if (! $this->option('now')) {
            $query->each(function (MemoryMedia $media): void {
                WarmDerivatives::dispatch($media->id);
            });

            $this->components->info("Queued {$total} file(s). A queue worker has to be running for anything to happen.");

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $failed = 0;

        $query->each(function (MemoryMedia $media) use ($bar, &$failed): void {
            try {
                // Straight through the job, so warming by hand and warming on
                // the queue cannot drift into doing two different things.
                app()->call([new WarmDerivatives($media->id), 'handle']);
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->components->warn("{$media->uuid}: {$e->getMessage()}");
            }

            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);

        $this->components->info(sprintf('Warmed %d of %d.', $total - $failed, $total));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
