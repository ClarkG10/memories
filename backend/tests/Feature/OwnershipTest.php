<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Memory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An archive belongs to one person.
 *
 * Every edit is guarded by `$user->id === $memory->user_id`, so a second users
 * row splits the archive: signing in as one shows every memory and lets you
 * change none of the other's. From the browser that is "not authorized" and
 * nothing else — no hint that there are two of you.
 */
class OwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function second(): User
    {
        return User::factory()->create(['email' => 'second@example.test']);
    }

    public function test_a_memory_made_under_another_account_is_still_editable(): void
    {
        $theirs = Memory::factory()->for($this->second())->create(['title' => 'Recovered']);

        $this->signedInOwner();

        /*
         | The failure this file was written for. A second users row appears
         | more easily than it should — `archive:owner` given a different email
         | makes one without comment — and a per-record check then made every
         | memory under it permanently uneditable, while saying only "This
         | action is unauthorized" about it. The archive has one owner; being
         | signed in is what that means.
         */
        $this->patchJson("/api/memories/{$theirs->uuid}", ['title' => 'Mine'])->assertOk();

        $this->assertSame('Mine', $theirs->fresh()->title);
    }

    public function test_a_visitor_still_cannot_change_anything(): void
    {
        $theirs = Memory::factory()->for($this->second())->create(['title' => 'Theirs']);

        // Loosening who counts as the owner must not loosen whether there is
        // one at all.
        $this->patchJson("/api/memories/{$theirs->uuid}", ['title' => 'Mine'])->assertUnauthorized();
        $this->deleteJson("/api/memories/{$theirs->uuid}")->assertUnauthorized();

        $this->assertSame('Theirs', $theirs->fresh()->title);
    }

    public function test_the_doctor_says_when_an_archive_is_split(): void
    {
        Memory::factory()->for($this->owner())->create();
        Memory::factory()->for($this->second())->create();

        $this->artisan('memories:doctor')
            ->expectsOutputToContain('More than one account exists');
    }

    public function test_reassigning_puts_every_memory_under_one_owner(): void
    {
        $owner = $this->owner();
        $other = $this->second();

        Memory::factory()->count(2)->for($owner)->create();
        $stranded = Memory::factory()->for($other)->create();

        $this->artisan('memories:reassign --to='.$owner->email)->assertSuccessful();

        $this->assertSame($owner->id, $stranded->fresh()->user_id);
        $this->assertSame(3, Memory::query()->where('user_id', $owner->id)->count());
    }

    public function test_reassigning_is_tidiness_rather_than_a_repair(): void
    {
        $owner = $this->owner();
        $stranded = Memory::factory()->for($this->second())->create(['title' => 'Recovered']);

        $this->signedInOwner();

        // Editable before and after: the command exists to keep one archive
        // under one name, not because anything depends on it.
        $this->patchJson("/api/memories/{$stranded->uuid}", ['title' => 'One'])->assertOk();

        $this->artisan('memories:reassign --to='.$owner->email)->assertSuccessful();

        $this->patchJson("/api/memories/{$stranded->uuid}", ['title' => 'Two'])->assertOk();
        $this->assertSame($owner->id, $stranded->fresh()->user_id);
    }

    public function test_a_deleted_memory_moves_too(): void
    {
        $owner = $this->owner();
        $gone = Memory::factory()->for($this->second())->create();
        $gone->delete();

        // Left behind it would be stranded again the moment it was restored.
        $this->artisan('memories:reassign --to='.$owner->email)->assertSuccessful();

        $this->assertSame($owner->id, Memory::withTrashed()->find($gone->id)->user_id);
    }

    public function test_reassigning_refuses_rather_than_choosing_for_you(): void
    {
        $this->owner();
        $this->second();

        $this->artisan('memories:reassign')
            ->expectsOutputToContain('Say who they should belong to')
            ->assertFailed();
    }

    public function test_reassigning_to_an_address_that_is_not_an_owner_is_refused(): void
    {
        $this->owner();

        $this->artisan('memories:reassign --to=nobody@example.test')
            ->expectsOutputToContain('No owner with the address')
            ->assertFailed();
    }

    public function test_importing_will_not_guess_between_two_owners(): void
    {
        $this->owner();
        $this->second();

        $this->drive->files['drive-1'] = ['name' => '2025-11-23 A day 01.jpg', 'folder' => 'f', 'bytes' => 10];

        /*
         | The failure this whole file is about: guessing here produces a
         | memory owned by whichever row happened to be first, and the person
         | who asked for it cannot touch what they just recovered.
         */
        $this->artisan('memories:import --no-interaction')
            ->expectsOutputToContain('has more than one owner')
            ->assertFailed();

        $this->assertSame(0, Memory::query()->count());
    }

    public function test_importing_accepts_the_owner_being_named(): void
    {
        $this->owner();
        $second = $this->second();

        $this->drive->files['drive-1'] = ['name' => '2025-11-23 A day 01.jpg', 'folder' => 'f', 'bytes' => 10];

        $this->artisan('memories:import --no-interaction --owner='.$second->email)->assertSuccessful();

        $this->assertSame($second->id, Memory::firstOrFail()->user_id);
    }
}
