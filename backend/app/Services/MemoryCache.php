<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Caching for the timeline, with invalidation that cannot go stale.
 *
 * Every key carries a version number. Writing anything bumps the counter, and
 * the entire previous generation becomes unreachable in one atomic step —
 * no key enumeration, no cache tags, no driver requirements, and no window
 * where a visitor sees a memory that was just deleted.
 *
 * The trade is that editing one memory retires the whole generation. For an
 * archive that is read constantly and written a few times a week, that is the
 * right way round: correctness costs almost nothing here.
 */
class MemoryCache
{
    private const VERSION_KEY = 'memories:generation';

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * A page of the timeline.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function timeline(string $signature, Closure $callback): mixed
    {
        return $this->cache->remember(
            $this->key("timeline:{$signature}"),
            (int) config('memories.cache.ttl.timeline'),
            $callback,
        );
    }

    /**
     * The years that contain memories, with counts — the timeline's spine.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function years(Closure $callback): mixed
    {
        return $this->cache->remember(
            $this->key('years'),
            (int) config('memories.cache.ttl.years'),
            $callback,
        );
    }

    /**
     * The albums in use. Same shape of thing as the year list: a small facet
     * read constantly and written rarely.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function albums(Closure $callback): mixed
    {
        return $this->cache->remember(
            $this->key('albums'),
            (int) config('memories.cache.ttl.years'),
            $callback,
        );
    }

    /**
     * One memory in full, as returned when it is opened.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function memory(string $uuid, Closure $callback): mixed
    {
        return $this->cache->remember(
            $this->key("memory:{$uuid}"),
            (int) config('memories.cache.ttl.memory'),
            $callback,
        );
    }

    /**
     * Retire every cached read. Called after any create, edit or delete.
     */
    public function flush(): void
    {
        /*
         | Never allowed to throw. This runs after the database has already
         | committed, so an unreachable Redis would turn a memory that really
         | was removed into a 500 for the person who removed it — and they
         | would try again, on something that has already happened.
         */
        try {
            // Read first, so the counter is known to exist. Incrementing a
            // missing key creates it at 1, which is exactly the generation a
            // stale entry might still be sitting under.
            $current = $this->version();

            if ($this->cache->increment(self::VERSION_KEY) === false) {
                $this->cache->forever(self::VERSION_KEY, $current + 1);
            }
        } catch (Throwable $e) {
            Log::error('Could not retire the cached timeline; it may be stale until it expires.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function version(): int
    {
        $version = $this->cache->get(self::VERSION_KEY);

        if (is_numeric($version)) {
            return (int) $version;
        }

        $version = $this->newGeneration();

        $this->cache->forever(self::VERSION_KEY, $version);

        return $version;
    }

    /**
     * A generation number for a counter that has gone missing — evicted under
     * memory pressure, or lost to a Redis restart.
     *
     * Deliberately random rather than 1, and rather than the clock. The
     * counter carries no TTL while the entries it namespaces do, so it is the
     * one key that can outlive its generation or be outlived by it. Restarting
     * at a number that was used before would bring every surviving entry from
     * that generation back to life at once — including memories that have
     * since been deleted, which is the one thing this cache must never do.
     */
    private function newGeneration(): int
    {
        return random_int(1_000, PHP_INT_MAX >> 16);
    }

    private function key(string $suffix): string
    {
        return 'memories:v'.$this->version().':'.$suffix;
    }
}
