<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MemoryCache;
use Illuminate\Console\Command;

/**
 * Retire every cached read of the archive.
 *
 * Writing a memory does this by itself. This is for the times it did not
 * happen — Redis unreachable at the moment of a save, a row changed directly
 * in the database, a restore from a backup — where the archive is correct and
 * only the answers being served are old.
 */
class RefreshCommand extends Command
{
    protected $signature = 'memories:refresh';

    protected $description = 'Retire the cached timeline, year list and albums';

    public function handle(MemoryCache $cache): int
    {
        $cache->flush();

        $this->components->info('Cached reads retired. The next request rebuilds them from the database.');

        return self::SUCCESS;
    }
}
