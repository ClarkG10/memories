<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Memory;
use App\Models\MemoryMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The command that answers "a year is in Google Drive but not in the app".
 *
 * That question has a small number of answers and they are hard to tell apart
 * from the outside: the memory was never saved, it was deleted, or the answer
 * being served is older than the memory. Guessing between them from a browser
 * is hopeless, so the command says which.
 */
class DoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_the_years_straight_from_the_database(): void
    {
        Memory::factory()->for($this->owner())->on('2026-05-01')->create();
        Memory::factory()->count(2)->for($this->owner())->on('2025-11-23')->create();

        $this->artisan('memories:doctor')
            ->expectsOutputToContain('2026  1 memory')
            ->expectsOutputToContain('2025  2 memories');
    }

    public function test_a_deleted_year_is_shown_rather_than_simply_absent(): void
    {
        Memory::factory()->for($this->owner())->on('2026-05-01')->create();
        $gone = Memory::factory()->for($this->owner())->on('2025-11-23')->create();

        $gone->delete();

        /*
         | This is the whole point. The year vanishes from the archive the
         | instant the memory is soft-deleted, while its files sit in Drive
         | under a 2025 folder until the queue collects them — which looks
         | exactly like the archive having lost them.
         */
        $this->artisan('memories:doctor')
            ->expectsOutputToContain('deleted, still in Drive');
    }

    public function test_it_says_when_nothing_is_consuming_the_queue(): void
    {
        $memory = Memory::factory()->for($this->owner())->create();

        MemoryMedia::factory()->for($memory)->create([
            'deletion_state' => MemoryMedia::DELETION_DELETING,
            'deletion_requested_at' => now()->subHour(),
        ]);

        $this->artisan('memories:doctor')
            ->expectsOutputToContain('waiting over 15 minutes')
            ->expectsOutputToContain('check the queue worker is running')
            ->assertFailed();
    }

    public function test_a_deletion_that_only_just_started_is_not_a_problem(): void
    {
        $memory = Memory::factory()->for($this->owner())->create();

        // A minute old is a queue doing its job, not a queue that is down.
        MemoryMedia::factory()->for($memory)->create([
            'deletion_state' => MemoryMedia::DELETION_DELETING,
            'deletion_requested_at' => now()->subMinute(),
        ]);

        $this->artisan('memories:doctor')->assertSuccessful();
    }
}
