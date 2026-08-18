<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Memory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding one memory in an archive that has outgrown scrolling.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    private function memory(array $attributes): Memory
    {
        return Memory::factory()->for($this->owner())->create($attributes);
    }

    /** @return array<int, string> */
    private function search(string $phrase): array
    {
        return collect($this->getJson('/api/timeline?q='.urlencode($phrase))->assertOk()->json('data'))
            ->pluck('title')
            ->all();
    }

    public function test_it_finds_a_memory_by_its_title(): void
    {
        $this->memory(['title' => 'That Beautiful Evening']);
        $this->memory(['title' => 'Breakfast in the rain']);

        $this->assertSame(['That Beautiful Evening'], $this->search('evening'));
    }

    public function test_it_looks_in_the_words_underneath_as_well(): void
    {
        $this->memory(['title' => 'A quiet day', 'description' => 'we ate mangoes on the pier']);
        $this->memory(['title' => 'Another day']);

        $this->assertSame(['A quiet day'], $this->search('mangoes'));
    }

    public function test_it_looks_in_the_place_and_the_album(): void
    {
        $this->memory(['title' => 'One', 'location' => 'Duka Bay']);
        $this->memory(['title' => 'Two', 'album' => 'Our Wedding']);
        $this->memory(['title' => 'Three']);

        $this->assertSame(['One'], $this->search('duka'));
        $this->assertSame(['Two'], $this->search('wedding'));
    }

    public function test_every_word_must_appear_but_not_all_in_the_same_place(): void
    {
        $wanted = $this->memory([
            'title' => 'Our Wedding',
            'location' => 'Butuan',
        ]);

        $this->memory(['title' => 'Our Wedding', 'location' => 'Cebu']);
        $this->memory(['title' => 'A day out', 'location' => 'Butuan']);

        /*
         | The pair of expectations that makes a search box feel right. An OR
         | across words would return all three; an AND inside one column would
         | return none, because the two halves live in different columns.
         */
        $this->assertSame([$wanted->title.''], array_values(array_unique($this->search('wedding butuan'))));
        $this->assertCount(1, $this->search('wedding butuan'));
    }

    public function test_a_year_is_matched_against_the_date_as_well(): void
    {
        $this->memory(['title' => 'Older', 'memory_date' => '2025-11-23']);
        $this->memory(['title' => 'Newer', 'memory_date' => '2026-05-01']);

        // Nothing says "2025" in words; it is only true of the date.
        $this->assertSame(['Older'], $this->search('2025'));
    }

    public function test_searching_is_not_case_sensitive(): void
    {
        $this->memory(['title' => 'Duka Bay']);

        $this->assertSame(['Duka Bay'], $this->search('DUKA'));
    }

    public function test_a_wildcard_character_searches_for_itself(): void
    {
        $this->memory(['title' => '100% ours']);
        $this->memory(['title' => 'Something else entirely']);

        // Unescaped, "%" matches every memory in the archive.
        $this->assertSame(['100% ours'], $this->search('100%'));
    }

    public function test_an_underscore_is_not_treated_as_a_single_character_wildcard(): void
    {
        $this->memory(['title' => 'beach_day']);
        $this->memory(['title' => 'beachXday']);

        $this->assertSame(['beach_day'], $this->search('beach_day'));
    }

    public function test_nothing_matching_is_an_empty_answer_rather_than_everything(): void
    {
        $this->memory(['title' => 'A quiet day']);

        $this->assertSame([], $this->search('rollerblading'));
    }

    public function test_an_empty_phrase_returns_the_whole_timeline(): void
    {
        $this->memory(['title' => 'One']);
        $this->memory(['title' => 'Two']);

        $this->assertCount(2, $this->search('   '));
    }

    public function test_a_search_is_not_answered_from_the_unsearched_cache(): void
    {
        $this->memory(['title' => 'That Beautiful Evening']);
        $this->memory(['title' => 'Breakfast in the rain']);

        // Warm the plain timeline first, exactly as opening the archive does.
        $this->assertCount(2, $this->search(''));

        // If the phrase were left out of the cache key, this would be two.
        $this->assertSame(['That Beautiful Evening'], $this->search('evening'));
    }

    public function test_a_search_can_be_narrowed_to_a_year(): void
    {
        $this->memory(['title' => 'Beach day', 'memory_date' => '2025-07-01']);
        $this->memory(['title' => 'Beach day again', 'memory_date' => '2026-07-01']);

        $found = collect($this->getJson('/api/timeline?q=beach&year=2026')->assertOk()->json('data'))
            ->pluck('title')
            ->all();

        $this->assertSame(['Beach day again'], $found);
    }

    public function test_a_stranger_cannot_search_a_private_archive(): void
    {
        config(['memories.public' => false]);

        $this->memory(['title' => 'Private thing']);

        $this->getJson('/api/timeline?q=private')->assertForbidden();
    }
}
