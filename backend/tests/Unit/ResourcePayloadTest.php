<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Resources\MemoryResource;
use App\Http\Resources\TimelineMemoryResource;
use App\Models\Memory;
use App\Models\MemoryMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Timeline payloads are cached, which puts one hard requirement on them: they
 * must be plain data.
 *
 * A resource collection left unresolved works perfectly on the request that
 * builds it — Laravel resolves it on the way out — and then comes back from a
 * serialising cache as an unusable object. The first visitor sees their
 * memories and every visitor after that sees nothing.
 *
 * Asserting the shape here catches it whatever cache driver is configured,
 * including the in-memory one used by the rest of the suite, which stores
 * objects happily and would let the mistake through.
 */
class ResourcePayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_timeline_entry_contains_nothing_but_plain_data(): void
    {
        $memory = Memory::factory()->for($this->owner())->create(['media_count' => 3]);
        MemoryMedia::factory()->count(3)->for($memory)->create();

        $memory->load('media');

        $payload = (new TimelineMemoryResource($memory))->toArray(Request::create('/api/timeline'));

        $this->assertPlainData($payload);
        $this->assertIsList($payload['preview']);
    }

    public function test_a_full_memory_contains_nothing_but_plain_data(): void
    {
        $memory = Memory::factory()->for($this->owner())->create(['media_count' => 2]);
        MemoryMedia::factory()->count(2)->for($memory)->create();

        $memory->load('media');

        $payload = (new MemoryResource($memory))->toArray(Request::create('/api/memories/x'));

        $this->assertPlainData($payload);
        $this->assertIsList($payload['media']);
        $this->assertCount(2, $payload['media']);
    }

    public function test_media_is_an_empty_list_when_the_relation_was_not_loaded(): void
    {
        $memory = Memory::factory()->for($this->owner())->create();

        $payload = (new MemoryResource($memory))->toArray(Request::create('/api/memories/x'));

        $this->assertSame([], $payload['media']);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function assertPlainData(array $value, string $path = 'root'): void
    {
        foreach ($value as $key => $item) {
            $where = "{$path}.{$key}";

            if (is_array($item)) {
                $this->assertPlainData($item, $where);

                continue;
            }

            $this->assertTrue(
                $item === null || is_scalar($item),
                "Expected {$where} to be plain data, found ".get_debug_type($item).'.',
            );
        }
    }
}
