<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Memory;
use App\Models\MemoryMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_archive_returns_an_empty_timeline_rather_than_an_error(): void
    {
        $this->getJson('/api/timeline')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_memories_arrive_newest_first(): void
    {
        $owner = $this->owner();

        foreach (['2024-03-02', '2026-08-10', '2025-12-25'] as $date) {
            Memory::factory()->for($owner)->on($date)->create(['title' => "Memory {$date}"]);
        }

        $response = $this->getJson('/api/timeline')->assertOk();

        $this->assertSame(
            ['2026-08-10', '2025-12-25', '2024-03-02'],
            array_column($response->json('data'), 'memory_date'),
        );
    }

    public function test_the_timeline_carries_a_preview_but_not_the_whole_memory(): void
    {
        $memory = Memory::factory()->for($this->owner())->create([
            'title' => 'That Beautiful Evening',
            'description' => 'A long description that the timeline has no business sending.',
            'media_count' => 5,
        ]);

        MemoryMedia::factory()->count(5)->for($memory)->sequence(
            fn ($sequence) => ['sort_order' => $sequence->index],
        )->create();

        $response = $this->getJson('/api/timeline')->assertOk();

        $response->assertJsonPath('data.0.title', 'That Beautiful Evening');
        $response->assertJsonPath('data.0.media_count', 5);
        $response->assertJsonMissingPath('data.0.description');

        // Only enough media to compose the card.
        $this->assertCount(3, $response->json('data.0.preview'));
    }

    public function test_media_never_exposes_its_drive_identity(): void
    {
        $memory = Memory::factory()->for($this->owner())->create();
        $media = MemoryMedia::factory()->for($memory)->create();

        $body = $this->getJson('/api/timeline')->assertOk()->content();

        $this->assertStringNotContainsString($media->drive_file_id, $body);
        $this->assertStringNotContainsString('drive.google.com', $body);
        $this->assertStringNotContainsString('"'.$media->id.'"', $body);
    }

    public function test_the_cursor_walks_the_whole_archive_without_repeating(): void
    {
        $owner = $this->owner();

        // Same date throughout, so ordering has to fall back to the tiebreaker.
        Memory::factory()->count(7)->for($owner)->on('2026-01-01')->create();

        $seen = [];
        $cursor = null;

        do {
            $response = $this->getJson('/api/timeline?limit=3'.($cursor ? "&cursor={$cursor}" : ''))
                ->assertOk();

            $seen = array_merge($seen, array_column($response->json('data'), 'id'));
            $cursor = $response->json('meta.next_cursor');
        } while ($cursor !== null);

        $this->assertCount(7, $seen);
        $this->assertSame($seen, array_unique($seen));
    }

    public function test_the_timeline_can_be_narrowed_to_a_single_year(): void
    {
        $owner = $this->owner();

        Memory::factory()->for($owner)->on('2026-05-01')->create();
        Memory::factory()->count(2)->for($owner)->on('2024-05-01')->create();

        $this->getJson('/api/timeline?year=2024')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/memories/year/2026')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_years_are_reported_with_their_counts(): void
    {
        $owner = $this->owner();

        Memory::factory()->for($owner)->on('2026-05-01')->create();
        Memory::factory()->count(3)->for($owner)->on('2024-05-01')->create();

        $this->getJson('/api/timeline/years')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['year' => 2026, 'count' => 1],
                ['year' => 2024, 'count' => 3],
            ]]);
    }

    /**
     * The symptom this covers: a year that simply never appears. A page holds
     * 24 memories, so once a recent year has more than that, every earlier
     * year sits on the second page — and if the walk stops after the first,
     * they are all invisible while nothing anywhere reports an error.
     */
    public function test_an_earlier_year_is_reachable_by_walking_the_cursor(): void
    {
        $owner = $this->owner();

        Memory::factory()->count(30)->for($owner)->on('2026-05-01')->create();
        Memory::factory()->count(4)->for($owner)->on('2025-07-30')->create();

        $first = $this->getJson('/api/timeline')->assertOk();

        $this->assertCount(24, $first->json('data'));
        $this->assertTrue($first->json('meta.has_more'));

        // Nothing from 2025 on the first page: it is entirely 2026.
        $this->assertSame(
            ['2026'],
            array_values(array_unique(array_map(
                fn (array $memory): string => substr((string) $memory['memory_date'], 0, 4),
                $first->json('data'),
            ))),
        );

        $seen = [];
        $cursor = $first->json('meta.next_cursor');
        $pages = 0;

        while ($cursor !== null && $pages < 10) {
            $next = $this->getJson('/api/timeline?cursor='.urlencode((string) $cursor))->assertOk();

            foreach ($next->json('data') as $memory) {
                $seen[] = substr((string) $memory['memory_date'], 0, 4);
            }

            $cursor = $next->json('meta.next_cursor');
            $pages++;
        }

        $this->assertContains('2025', $seen, 'The earlier year never arrived.');
        $this->assertSame(4, count(array_filter($seen, fn (string $year): bool => $year === '2025')));
    }

    /** The year list is the archive's spine and must span every year in it. */
    public function test_the_year_list_spans_years_beyond_the_first_page(): void
    {
        $owner = $this->owner();

        Memory::factory()->count(30)->for($owner)->on('2026-05-01')->create();
        Memory::factory()->count(4)->for($owner)->on('2025-07-30')->create();

        $this->getJson('/api/timeline/years')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['year' => 2026, 'count' => 30],
                ['year' => 2025, 'count' => 4],
            ]]);
    }

    public function test_a_private_archive_shows_a_stranger_nothing(): void
    {
        config(['memories.public' => false]);

        Memory::factory()->for($this->owner())->create();

        $this->getJson('/api/timeline')->assertForbidden();

        // The owner still sees everything.
        $this->signedInOwner();
        $this->getJson('/api/timeline')->assertOk();
    }

    public function test_the_archive_endpoint_answers_even_when_private_so_the_app_can_offer_a_sign_in(): void
    {
        config(['memories.public' => false]);

        $this->getJson('/api/archive')
            ->assertOk()
            ->assertJsonPath('data.public', false)
            ->assertJsonPath('data.can_manage', false);
    }

    public function test_opening_a_memory_returns_its_full_detail(): void
    {
        $memory = Memory::factory()->for($this->owner())->create([
            'title' => 'That Beautiful Evening',
            'description' => 'One of those evenings we wish we could replay.',
            'location' => 'Butuan',
        ]);

        MemoryMedia::factory()->count(2)->for($memory)->create();

        $this->getJson("/api/memories/{$memory->uuid}")
            ->assertOk()
            ->assertJsonPath('data.title', 'That Beautiful Evening')
            ->assertJsonPath('data.location', 'Butuan')
            ->assertJsonPath('data.description', 'One of those evenings we wish we could replay.')
            ->assertJsonCount(2, 'data.media');
    }

    public function test_a_missing_memory_reads_as_gone_rather_than_broken(): void
    {
        $this->getJson('/api/memories/'.fake()->uuid())
            ->assertNotFound()
            ->assertJsonPath('message', 'That memory is no longer here.');
    }
}
