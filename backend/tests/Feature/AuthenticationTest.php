<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Memory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function ownerWithPassword(string $password = 'a-long-enough-password'): User
    {
        return User::factory()->create([
            'email' => 'owner@example.test',
            'password' => Hash::make($password),
        ]);
    }

    public function test_the_owner_signs_in_and_receives_a_token(): void
    {
        $this->ownerWithPassword();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'owner@example.test',
            'password' => 'a-long-enough-password',
        ])->assertOk();

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'owner@example.test');
    }

    public function test_a_wrong_password_is_refused_without_saying_which_part_was_wrong(): void
    {
        $this->ownerWithPassword();

        $this->postJson('/api/auth/login', [
            'email' => 'owner@example.test',
            'password' => 'not-the-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Those details do not match our records.');
    }

    public function test_an_unknown_address_gets_the_same_answer_as_a_wrong_password(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.test',
            'password' => 'whatever',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Those details do not match our records.');
    }

    public function test_repeated_guesses_are_throttled(): void
    {
        $this->ownerWithPassword();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'owner@example.test',
                'password' => 'wrong',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'owner@example.test',
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_signing_out_stops_the_token_working(): void
    {
        $this->ownerWithPassword();

        $token = $this->postJson('/api/auth/login', [
            'email' => 'owner@example.test',
            'password' => 'a-long-enough-password',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertSame(0, PersonalAccessToken::query()->count());

        /*
         | Every test request reuses one application instance, so the guard is
         | still holding the user it resolved a moment ago. A real second
         | request would boot fresh; this reproduces that.
         */
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_every_management_endpoint_refuses_a_visitor(): void
    {
        $memory = Memory::factory()->for($this->owner())->create();

        $this->postJson('/api/memories', [])->assertUnauthorized();
        $this->patchJson("/api/memories/{$memory->uuid}", ['title' => 'Nope'])->assertUnauthorized();
        $this->deleteJson("/api/memories/{$memory->uuid}")->assertUnauthorized();
        $this->postJson("/api/memories/{$memory->uuid}/media", [])->assertUnauthorized();
        $this->postJson('/api/uploads', [])->assertUnauthorized();
    }

    public function test_someone_elses_memory_cannot_be_edited_or_removed(): void
    {
        $memory = Memory::factory()->for($this->owner())->create(['title' => 'Theirs']);

        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->patchJson("/api/memories/{$memory->uuid}", ['title' => 'Mine now'])->assertForbidden();
        $this->deleteJson("/api/memories/{$memory->uuid}")->assertForbidden();

        $this->assertSame('Theirs', $memory->fresh()->title);
    }

    public function test_the_owner_can_edit_a_memory(): void
    {
        $owner = $this->signedInOwner();
        $memory = Memory::factory()->for($owner)->create(['title' => 'Before']);

        $this->patchJson("/api/memories/{$memory->uuid}", [
            'title' => 'After',
            'location' => 'Butuan',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'After')
            ->assertJsonPath('data.location', 'Butuan');
    }

    public function test_a_memory_cannot_be_dated_in_the_future(): void
    {
        $owner = $this->signedInOwner();
        $memory = Memory::factory()->for($owner)->create();

        $this->patchJson("/api/memories/{$memory->uuid}", [
            'memory_date' => now()->addWeek()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('memory_date');
    }
}
