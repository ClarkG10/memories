<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Memory;
use App\Models\MemoryMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixing a memory after the fact.
 *
 * The wrong photograph gets uploaded, or the best one ends up third. Neither
 * should mean deleting the memory and starting again, and neither should be
 * able to leave the memory in a state it cannot be drawn from.
 */
class MemoryMediaEditingTest extends TestCase
{
    use RefreshDatabase;

    private function aMemoryWithMedia(int $count = 3): Memory
    {
        $memory = Memory::factory()->for($this->owner())->create(['media_count' => $count]);

        for ($i = 0; $i < $count; $i++) {
            MemoryMedia::factory()->for($memory)->create([
                'sort_order' => $i,
                'deletion_state' => MemoryMedia::DELETION_ACTIVE,
            ]);
        }

        return $memory->fresh(['media']);
    }

    /** @return array<int, string> */
    private function orderOf(Memory $memory): array
    {
        return $memory->fresh()->media()->orderBy('sort_order')->pluck('uuid')->all();
    }

    public function test_the_wrong_photograph_can_be_removed(): void
    {
        $this->signedInOwner();

        $memory = $this->aMemoryWithMedia(3);
        $wrong = $memory->media[1];

        $this->deleteJson("/api/media/{$wrong->uuid}")->assertOk();

        $this->assertSame(2, $memory->fresh()->media()->count());
        $this->assertSame(2, (int) $memory->fresh()->media_count, 'The denormalised count must follow.');
        $this->assertNotContains($wrong->uuid, $this->orderOf($memory));
    }

    public function test_the_last_photograph_cannot_be_removed(): void
    {
        $this->signedInOwner();

        $memory = $this->aMemoryWithMedia(1);
        $only = $memory->media[0];

        /*
         | Otherwise the memory still exists, still counts towards its year,
         | and renders as nothing at all — present in every total and visible
         | nowhere. Deleting the memory is a different act, and it asks for the
         | title to be typed first.
         */
        $this->deleteJson("/api/media/{$only->uuid}")
            ->assertStatus(422)
            ->assertJsonPath('errors.media.0', 'A memory has to keep at least one photo or video. Delete the whole memory instead.');

        $this->assertSame(1, $memory->fresh()->media()->count());
    }

    public function test_photographs_can_be_put_in_a_different_order(): void
    {
        $this->signedInOwner();

        $memory = $this->aMemoryWithMedia(3);
        [$first, $second, $third] = $this->orderOf($memory);

        // Promote the third to lead: it is the one that shows in the timeline.
        $this->putJson("/api/memories/{$memory->uuid}/media/order", [
            'order' => [$third, $first, $second],
        ])->assertOk();

        $this->assertSame([$third, $first, $second], $this->orderOf($memory));
    }

    public function test_the_new_lead_is_what_the_timeline_shows(): void
    {
        $this->signedInOwner();

        $memory = $this->aMemoryWithMedia(3);
        $order = $this->orderOf($memory);
        $promoted = $order[2];

        $this->putJson("/api/memories/{$memory->uuid}/media/order", [
            'order' => [$promoted, $order[0], $order[1]],
        ])->assertOk();

        $preview = $this->getJson('/api/timeline')->assertOk()->json('data.0.preview');

        $this->assertSame($promoted, $preview[0]['id'], 'The promoted file must lead the card.');
    }

    public function test_a_partial_order_is_refused_rather_than_half_applied(): void
    {
        $this->signedInOwner();

        $memory = $this->aMemoryWithMedia(3);
        $before = $this->orderOf($memory);

        // Two of three: the missing one would keep whatever position it had,
        // and two files would then claim the same one.
        $this->putJson("/api/memories/{$memory->uuid}/media/order", [
            'order' => [$before[2], $before[0]],
        ])->assertStatus(422);

        $this->assertSame($before, $this->orderOf($memory), 'Nothing may have moved.');
    }

    public function test_an_order_naming_another_memorys_file_is_refused(): void
    {
        $this->signedInOwner();

        $memory = $this->aMemoryWithMedia(2);
        $other = $this->aMemoryWithMedia(1);
        $before = $this->orderOf($memory);

        $this->putJson("/api/memories/{$memory->uuid}/media/order", [
            'order' => [$before[0], $other->media[0]->uuid],
        ])->assertStatus(422);

        $this->assertSame($before, $this->orderOf($memory));
    }

    public function test_a_stranger_cannot_rearrange_someone_elses_memory(): void
    {
        $memory = $this->aMemoryWithMedia(2);
        $order = $this->orderOf($memory);

        $this->putJson("/api/memories/{$memory->uuid}/media/order", [
            'order' => array_reverse($order),
        ])->assertUnauthorized();

        $this->assertSame($order, $this->orderOf($memory));
    }

    public function test_a_stranger_cannot_remove_a_photograph(): void
    {
        $memory = $this->aMemoryWithMedia(2);

        $this->deleteJson("/api/media/{$memory->media[0]->uuid}")->assertUnauthorized();

        $this->assertSame(2, $memory->fresh()->media()->count());
    }
}
