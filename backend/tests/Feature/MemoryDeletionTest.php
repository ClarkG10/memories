<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeleteDriveFile;
use App\Models\Memory;
use App\Models\MemoryMedia;
use App\Services\GoogleDrive\GoogleDriveException;
use App\Services\MemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MemoryDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_removed_memory_leaves_the_timeline_at_once(): void
    {
        Queue::fake();

        $owner = $this->signedInOwner();
        $memory = Memory::factory()->for($owner)->create(['media_count' => 1]);
        MemoryMedia::factory()->for($memory)->create();

        $this->deleteJson("/api/memories/{$memory->uuid}")->assertOk();

        $this->getJson('/api/timeline')->assertOk()->assertJsonPath('data', []);
        $this->getJson("/api/memories/{$memory->uuid}")->assertNotFound();

        $this->assertSoftDeleted('memories', ['id' => $memory->id]);

        // Marked for removal straight away, whether or not Drive has caught up.
        $this->assertSame(
            MemoryMedia::DELETION_DELETING,
            MemoryMedia::withTrashed()->firstOrFail()->deletion_state,
        );

        Queue::assertPushed(DeleteDriveFile::class);
    }

    public function test_media_stops_being_served_the_moment_removal_is_requested(): void
    {
        Queue::fake();

        $owner = $this->signedInOwner();
        $memory = Memory::factory()->for($owner)->create(['media_count' => 1]);
        $media = MemoryMedia::factory()->for($memory)->create();

        $this->deleteJson("/api/memories/{$memory->uuid}")->assertOk();

        // Even though Drive still holds the bytes at this point.
        $this->getJson("/api/media/{$media->uuid}/image")->assertNotFound();
    }

    public function test_the_drive_file_is_deleted_and_its_cached_renditions_with_it(): void
    {
        // Held back so the job can be run deliberately, once.
        Queue::fake();

        $owner = $this->owner();
        $memory = Memory::factory()->for($owner)->create(['media_count' => 1]);
        $media = MemoryMedia::factory()->for($memory)->create();

        $this->drive->files[$media->drive_file_id] = ['name' => 'x.jpg', 'folder' => 'f', 'bytes' => 10];
        Storage::disk('derivatives')->put("{$media->uuid}/w640.jpg", 'cached');

        app(MemoryService::class)->delete($memory);

        (new DeleteDriveFile($media->id))->handle(app(MemoryService::class));

        $media->refresh();

        $this->assertSame(MemoryMedia::DELETION_DELETED, $media->deletion_state);
        $this->assertContains($media->drive_file_id, $this->drive->deleted);
        $this->assertFalse(Storage::disk('derivatives')->exists("{$media->uuid}/w640.jpg"));
    }

    public function test_a_drive_that_refuses_to_delete_is_recorded_rather_than_forgotten(): void
    {
        Queue::fake();

        $owner = $this->owner();
        $memory = Memory::factory()->for($owner)->create(['media_count' => 1]);
        $media = MemoryMedia::factory()->for($memory)->create();

        $this->drive->deleteException = new GoogleDriveException('Drive is unavailable.', 503);

        app(MemoryService::class)->delete($memory);

        try {
            (new DeleteDriveFile($media->id))->handle(app(MemoryService::class));
            $this->fail('The job should have failed so the queue retries it.');
        } catch (\RuntimeException) {
            // Expected: the failure has to be visible to the queue.
        }

        $media->refresh();

        $this->assertSame(MemoryMedia::DELETION_FAILED, $media->deletion_state);
        $this->assertStringContainsString('Drive is unavailable', (string) $media->deletion_error);

        /*
         | A 503 is Drive being briefly unreachable, not a reason to give up on
         | this file. Spending the attempt budget on transient failures means a
         | Drive outage of an hour or so permanently abandons every pending
         | deletion — leaving files in Drive the owner asked to remove.
         */
        $this->assertSame(0, $media->deletion_attempts);

        // And the file is still ours to deal with, not lost.
        $this->assertTrue(
            MemoryMedia::withTrashed()->awaitingDeletion()->whereKey($media->id)->exists(),
        );
    }

    public function test_a_refusal_that_will_not_fix_itself_does_spend_an_attempt(): void
    {
        Queue::fake();

        $owner = $this->owner();
        $memory = Memory::factory()->for($owner)->create(['media_count' => 1]);
        $media = MemoryMedia::factory()->for($memory)->create();

        // Permission denied is not going to resolve on its own.
        $this->drive->deleteException = new GoogleDriveException(
            'The caller does not have permission.',
            403,
            'insufficientFilePermissions',
        );

        app(MemoryService::class)->delete($memory);

        try {
            (new DeleteDriveFile($media->id))->handle(app(MemoryService::class));
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame(1, $media->fresh()->deletion_attempts);
    }

    public function test_failed_deletions_are_picked_up_again_by_the_sweep(): void
    {
        Queue::fake();

        $memory = Memory::factory()->for($this->owner())->create();
        MemoryMedia::factory()->for($memory)->deleteFailed()->create();

        $this->artisan('memories:retry-deletions')->assertSuccessful();

        Queue::assertPushed(DeleteDriveFile::class);
    }

    public function test_a_file_that_has_exhausted_its_attempts_is_reported_not_retried_forever(): void
    {
        Queue::fake();

        $memory = Memory::factory()->for($this->owner())->create();

        MemoryMedia::factory()->for($memory)->create([
            'deletion_state' => MemoryMedia::DELETION_FAILED,
            'deletion_attempts' => MemoryMedia::MAX_DELETION_ATTEMPTS,
            'deletion_error' => 'Drive kept refusing.',
        ]);

        $this->artisan('memories:retry-deletions')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->assertCount(1, app(MemoryService::class)->abandonedDeletions());
    }

    public function test_a_file_already_gone_from_drive_counts_as_deleted(): void
    {
        Queue::fake();

        $owner = $this->owner();
        $memory = Memory::factory()->for($owner)->create(['media_count' => 1]);
        $media = MemoryMedia::factory()->for($memory)->create();

        // Never registered with the fake, so it behaves as a 404 from Drive.
        app(MemoryService::class)->delete($memory);
        (new DeleteDriveFile($media->id))->handle(app(MemoryService::class));

        $this->assertSame(MemoryMedia::DELETION_DELETED, $media->fresh()->deletion_state);
    }

    public function test_one_file_can_be_removed_while_the_memory_stays(): void
    {
        Queue::fake();

        $owner = $this->signedInOwner();
        $memory = Memory::factory()->for($owner)->create(['media_count' => 2]);
        $keep = MemoryMedia::factory()->for($memory)->create(['sort_order' => 0]);
        $remove = MemoryMedia::factory()->for($memory)->create(['sort_order' => 1]);

        $this->deleteJson("/api/media/{$remove->uuid}")->assertOk();

        $this->assertSame(1, $memory->fresh()->media_count);
        $this->assertSoftDeleted('memory_media', ['id' => $remove->id]);

        $this->getJson("/api/memories/{$memory->uuid}")
            ->assertOk()
            ->assertJsonCount(1, 'data.media')
            ->assertJsonPath('data.media.0.id', $keep->uuid);
    }

    public function test_a_visitor_cannot_remove_anything(): void
    {
        $memory = Memory::factory()->for($this->owner())->create();
        $media = MemoryMedia::factory()->for($memory)->create();

        $this->deleteJson("/api/memories/{$memory->uuid}")->assertUnauthorized();
        $this->deleteJson("/api/media/{$media->uuid}")->assertUnauthorized();

        $this->assertNotSoftDeleted('memories', ['id' => $memory->id]);
    }
}
