<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Memory;
use App\Models\MemoryMedia;
use App\Services\MemoryCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Caching that hands back a memory someone just deleted would be worse than no
 * caching at all. These lock down the invalidation, not the speed.
 */
class CacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_timeline_is_actually_cached(): void
    {
        Memory::factory()->for($this->owner())->create();

        $this->getJson('/api/timeline')->assertOk();

        // A second read must not go back to the database.
        DB::enableQueryLog();
        $this->getJson('/api/timeline')->assertOk();

        $this->assertSame([], DB::getQueryLog());
    }

    public function test_a_cached_response_is_identical_to_the_one_that_filled_the_cache(): void
    {
        $memory = Memory::factory()->for($this->owner())->create(['media_count' => 2]);
        MemoryMedia::factory()->count(2)->for($memory)->create();

        /*
         | A regression guard with teeth. Caching the *rendered* payload only
         | works if that payload is plain data: an unresolved resource object
         | survives the first request intact and comes back from the cache as
         | an unusable serialised object, so the second visitor sees nonsense
         | where the photographs should be.
         */
        $first = $this->getJson('/api/timeline')->assertOk();
        $second = $this->getJson('/api/timeline')->assertOk();

        $this->assertSame($first->json(), $second->json());
        $this->assertIsList($second->json('data.0.preview'));
        $this->assertCount(2, $second->json('data.0.preview'));
        $this->assertSame(
            $first->json('data.0.preview.0.urls.thumb'),
            $second->json('data.0.preview.0.urls.thumb'),
        );
    }

    public function test_a_cached_memory_is_identical_to_the_one_that_filled_the_cache(): void
    {
        $memory = Memory::factory()->for($this->owner())->create(['media_count' => 3]);
        MemoryMedia::factory()->count(3)->for($memory)->create();

        $first = $this->getJson("/api/memories/{$memory->uuid}")->assertOk();
        $second = $this->getJson("/api/memories/{$memory->uuid}")->assertOk();

        $this->assertSame($first->json(), $second->json());
        $this->assertIsList($second->json('data.media'));
        $this->assertCount(3, $second->json('data.media'));
    }

    public function test_a_new_memory_appears_immediately_despite_the_cache(): void
    {
        $this->signedInOwner();

        $this->getJson('/api/timeline')->assertOk()->assertJsonPath('data', []);

        $this->postJson('/api/memories', [
            'title' => 'Just Now',
            'memory_date' => '2026-08-10',
            'uploads' => [$this->completeUpload($this->jpegBytes())],
        ], $this->idempotent())->assertCreated();

        $this->getJson('/api/timeline')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Just Now');
    }

    public function test_a_deleted_memory_disappears_immediately_despite_the_cache(): void
    {
        Queue::fake();

        $owner = $this->signedInOwner();
        $memory = Memory::factory()->for($owner)->create();
        MemoryMedia::factory()->for($memory)->create();

        // Warm both the list and the detail cache.
        $this->getJson('/api/timeline')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/memories/{$memory->uuid}")->assertOk();

        $this->deleteJson("/api/memories/{$memory->uuid}")->assertOk();

        $this->getJson('/api/timeline')->assertOk()->assertJsonPath('data', []);
        $this->getJson("/api/memories/{$memory->uuid}")->assertNotFound();
    }

    public function test_an_edit_is_reflected_immediately_despite_the_cache(): void
    {
        $owner = $this->signedInOwner();
        $memory = Memory::factory()->for($owner)->create(['title' => 'Before']);

        $this->getJson("/api/memories/{$memory->uuid}")->assertJsonPath('data.title', 'Before');

        $this->patchJson("/api/memories/{$memory->uuid}", ['title' => 'After'])->assertOk();

        $this->getJson("/api/memories/{$memory->uuid}")->assertJsonPath('data.title', 'After');
        $this->getJson('/api/timeline')->assertJsonPath('data.0.title', 'After');
    }

    public function test_the_year_list_keeps_up_with_the_archive(): void
    {
        $this->signedInOwner();

        $this->getJson('/api/timeline/years')->assertOk()->assertJsonPath('data', []);

        $this->postJson('/api/memories', [
            'title' => 'A Year Begins',
            'memory_date' => '2026-08-10',
            'uploads' => [$this->completeUpload($this->jpegBytes())],
        ], $this->idempotent())->assertCreated();

        $this->getJson('/api/timeline/years')
            ->assertOk()
            ->assertJsonPath('data.0.year', 2026)
            ->assertJsonPath('data.0.count', 1);
    }

    public function test_one_write_retires_the_whole_generation_of_cached_reads(): void
    {
        $cache = app(MemoryCache::class);

        $before = $cache->version();
        $cache->flush();

        $this->assertGreaterThan($before, $cache->version());
    }

    public function test_losing_the_generation_counter_cannot_revive_a_retired_generation(): void
    {
        $cache = app(MemoryCache::class);

        $first = $cache->version();
        $cache->flush();
        $second = $cache->version();

        /*
         | The counter has no TTL while the entries it namespaces do, so it is
         | the one key that can be evicted out from under a generation that is
         | still in the cache. Coming back as 1 — or as anything used before —
         | would republish every stale entry from that generation, deleted
         | memories included.
         */
        Cache::forget('memories:generation');

        $third = $cache->version();

        $this->assertNotSame($first, $third);
        $this->assertNotSame($second, $third);
        $this->assertNotSame(1, $third);

        // And it keeps working as a counter from wherever it restarted.
        $cache->flush();
        $this->assertSame($third + 1, $cache->version());
    }
}
