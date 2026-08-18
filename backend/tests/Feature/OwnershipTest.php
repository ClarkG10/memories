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

    public function test_a_memory_belonging_to_someone_else_cannot_be_edited(): void
    {
        $theirs = Memory::factory()->for($this->second())->create(['title' => 'Theirs']);

        $this->signedInOwner();

        // Visible, because reading is decided for the archive as a whole.
        $this->getJson("/api/memories/{$theirs->uuid}")->assertOk();

        // And not editable, which is the half that reaches the browser as a
        // bare refusal.
        $this->patchJson("/api/memories/{$theirs->uuid}", ['title' => 'Mine now'])->assertForbidden();
    }

    public function test_the_refusal_says_what_to_do_about_it(): void
    {
        $theirs = Memory::factory()->for($this->second())->create();

        $this->signedInOwner();

        /*
         | "This action is unauthorized" is what a boolean produces, and it
         | gives the owner nothing to act on while they stare at their own
         | memory refusing to be edited.
         */
        $this->patchJson("/api/memories/{$theirs->uuid}", ['title' => 'Mine'])
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'different sign-in')
                && str_contains($message, 'memories:reassign'));
    }

    public function test_the_same_reason_reaches_a_refused_photo_change(): void
    {
        $theirs = Memory::factory()->for($this->second())->create();

        $this->signedInOwner();

        $this->putJson("/api/memories/{$theirs->uuid}/media/order", ['order' => [fake()->uuid()]])
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'different sign-in'));
    }

    public function test_the_doctor_says_when_an_archive_is_split(): void
    {
        Memory::factory()->for($this->owner())->create();
        Memory::factory()->for($this->second())->create();

        $this->artisan('memories:doctor')
            ->expectsOutputToContain('more than one owner');
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

    public function test_reassigning_makes_the_stranded_memory_editable_again(): void
    {
        $owner = $this->owner();
        $stranded = Memory::factory()->for($this->second())->create(['title' => 'Recovered']);

        $this->signedInOwner();
        $this->patchJson("/api/memories/{$stranded->uuid}", ['title' => 'Mine'])->assertForbidden();

        $this->artisan('memories:reassign --to='.$owner->email)->assertSuccessful();

        $this->patchJson("/api/memories/{$stranded->uuid}", ['title' => 'Mine'])->assertOk();
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
