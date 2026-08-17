<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\IdempotencyService;
use App\Services\UploadSessionService;
use Illuminate\Console\Command;

/**
 * Clears out uploads that were abandoned part-way, and idempotency keys old
 * enough that nothing could still be retrying them.
 *
 * Without this, every cancelled upload would leave its partial file on disk
 * for good.
 */
class PruneUploadsCommand extends Command
{
    protected $signature = 'uploads:prune';

    protected $description = 'Delete abandoned upload sessions and expired request keys';

    public function handle(UploadSessionService $uploads, IdempotencyService $idempotency): int
    {
        $sessions = $uploads->pruneExpired();
        $keys = $idempotency->prune();

        $this->components->info("Pruned {$sessions} upload session(s) and {$keys} request key(s).");

        return self::SUCCESS;
    }
}
