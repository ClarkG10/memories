<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MemoryService;
use Illuminate\Console\Command;

/**
 * Re-queues Drive deletions that failed.
 *
 * Runs on the schedule so a Drive outage during a delete resolves itself once
 * Drive comes back, without anyone noticing there was a problem.
 */
class RetryDeletionsCommand extends Command
{
    protected $signature = 'memories:retry-deletions';

    protected $description = 'Retry Google Drive deletions that did not complete';

    public function handle(MemoryService $memories): int
    {
        $queued = $memories->retryFailedDeletions();

        $this->components->info(
            $queued === 0
                ? 'Nothing waiting to be deleted.'
                : "Re-queued {$queued} file(s) for deletion."
        );

        return self::SUCCESS;
    }
}
