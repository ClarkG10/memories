<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Models\Memory;
use App\Models\MemoryMedia;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\GoogleDrive\GoogleDriveException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MemoryCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_memory_is_created_from_chunked_uploads(): void
    {
        $this->signedInOwner();

        $upload = $this->completeUpload($this->jpegBytes(), 'evening.jpg');

        $response = $this->postJson('/api/memories', [
            'title' => 'That Beautiful Evening',
            'description' => 'One of those evenings we wish we could replay.',
            'memory_date' => '2026-08-10',
            'location' => 'Butuan',
            'uploads' => [$upload],
        ], $this->idempotent())->assertCreated();

        $response->assertJsonPath('data.title', 'That Beautiful Evening');
        $response->assertJsonPath('data.location', 'Butuan');
        $response->assertJsonCount(1, 'data.media');

        $memory = Memory::query()->firstOrFail();
        $this->assertSame(1, $memory->media_count);

        $media = $memory->media()->firstOrFail();
        $this->assertSame(MemoryMedia::TYPE_IMAGE, $media->type);
        $this->assertSame('image/jpeg', $media->mime_type);
        $this->assertSame(800, $media->width);
        $this->assertSame(600, $media->height);

        // The bytes actually reached Drive, and the file is named for a human
        // browsing the folder by hand.
        $this->assertSame(1, $this->drive->uploadCount());
        $this->assertSame('2026-08-10 That Beautiful Evening 01.jpg', $this->drive->uploadedNames[0]);
        $this->assertArrayHasKey($media->drive_file_id, $this->drive->files);
        $this->assertSame('folder-image-2026-08', $media->drive_folder_id);
    }

    public function test_several_files_become_one_memory_in_the_order_they_were_chosen(): void
    {
        $this->signedInOwner();

        $uploads = [
            $this->completeUpload($this->jpegBytes(800, 600), 'first.jpg'),
            $this->completeUpload($this->jpegBytes(640, 480), 'second.jpg'),
            $this->completeUpload($this->jpegBytes(400, 900), 'third.jpg'),
        ];

        $this->postJson('/api/memories', [
            'title' => 'A Day Together',
            'memory_date' => '2026-07-04',
            'uploads' => $uploads,
        ], $this->idempotent())->assertCreated()->assertJsonCount(3, 'data.media');

        $memory = Memory::query()->firstOrFail();

        $this->assertSame(3, $memory->media_count);
        $this->assertSame([0, 1, 2], $memory->media()->pluck('sort_order')->all());
        $this->assertSame(3, $this->drive->uploadCount());
    }

    public function test_a_photo_carries_an_inline_placeholder_so_the_timeline_has_something_to_show(): void
    {
        $this->signedInOwner();

        $this->postJson('/api/memories', [
            'title' => 'Morning',
            'memory_date' => '2026-02-02',
            'uploads' => [$this->completeUpload($this->jpegBytes())],
        ], $this->idempotent())->assertCreated();

        $media = MemoryMedia::query()->firstOrFail();

        $this->assertStringStartsWith('data:image/jpeg;base64,', (string) $media->placeholder);

        // Small enough to ship with every timeline card.
        $this->assertLessThan(3000, strlen((string) $media->placeholder));
    }

    public function test_the_upload_session_is_spent_and_its_temp_file_removed(): void
    {
        $this->signedInOwner();

        $upload = $this->completeUpload($this->jpegBytes());

        $this->postJson('/api/memories', [
            'title' => 'Morning',
            'memory_date' => '2026-02-02',
            'uploads' => [$upload],
        ], $this->idempotent())->assertCreated();

        $session = UploadSession::query()->where('uuid', $upload)->firstOrFail();

        $this->assertSame(UploadSession::STATUS_CONSUMED, $session->status);
        $this->assertFalse(Storage::disk('uploads')->exists($session->path));
    }

    public function test_replaying_the_same_request_does_not_create_a_second_memory(): void
    {
        $this->signedInOwner();

        $payload = [
            'title' => 'Only Once',
            'memory_date' => '2026-03-03',
            'uploads' => [$this->completeUpload($this->jpegBytes())],
        ];

        $first = $this->postJson('/api/memories', $payload, $this->idempotent('same-key'))->assertCreated();
        $second = $this->postJson('/api/memories', $payload, $this->idempotent('same-key'))->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Memory::query()->count());

        // Crucially, the video was not sent to Drive twice.
        $this->assertSame(1, $this->drive->uploadCount());
    }

    public function test_a_request_without_an_idempotency_key_is_refused(): void
    {
        $this->signedInOwner();

        $this->postJson('/api/memories', [
            'title' => 'Unprotected',
            'memory_date' => '2026-03-03',
            'uploads' => [$this->completeUpload($this->jpegBytes())],
        ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(0, Memory::query()->count());
    }

    public function test_reusing_a_key_for_a_different_memory_is_rejected(): void
    {
        $this->signedInOwner();

        $this->postJson('/api/memories', [
            'title' => 'First',
            'memory_date' => '2026-03-03',
            'uploads' => [$this->completeUpload($this->jpegBytes())],
        ], $this->idempotent('shared'))->assertCreated();

        $this->postJson('/api/memories', [
            'title' => 'Second',
            'memory_date' => '2026-04-04',
            'uploads' => [$this->completeUpload($this->jpegBytes())],
        ], $this->idempotent('shared'))->assertStatus(409);
    }

    public function test_a_failed_attempt_can_be_retried_with_the_same_key(): void
    {
        $this->signedInOwner();

        $upload = $this->completeUpload($this->jpegBytes());
        $payload = ['title' => 'Retry Me', 'memory_date' => '2026-05-05', 'uploads' => [$upload]];

        $this->drive->failUploadAtCall = 1;
        $this->postJson('/api/memories', $payload, $this->idempotent('retry'))->assertStatus(422);

        // The key must not be poisoned by the failure — the person is about to
        // press "Try again" with exactly the same one.
        $this->drive->failUploadAtCall = null;
        $this->postJson('/api/memories', $payload, $this->idempotent('retry'))->assertCreated();

        $this->assertSame(1, Memory::query()->count());
    }

    public function test_a_drive_failure_leaves_no_memory_and_no_stray_files(): void
    {
        $this->signedInOwner();

        $uploads = [
            $this->completeUpload($this->jpegBytes(800, 600), 'one.jpg'),
            $this->completeUpload($this->jpegBytes(640, 480), 'two.jpg'),
        ];

        // The first file lands, the second does not.
        $this->drive->failUploadAtCall = 2;

        $this->postJson('/api/memories', [
            'title' => 'Never Saved',
            'memory_date' => '2026-06-06',
            'uploads' => $uploads,
        ], $this->idempotent())
            ->assertStatus(422)
            ->assertJsonPath('message', "We couldn't finish uploading your memory. It hasn't been added yet — please try again.")
            ->assertJsonPath('retryable', true);

        $this->assertSame(0, Memory::query()->count());
        $this->assertSame(0, MemoryMedia::query()->count());

        // The half of the batch that did upload was taken back out of Drive.
        $this->assertSame([], $this->drive->files);
        $this->assertCount(1, $this->drive->deleted);
    }

    public function test_a_save_killed_mid_request_does_not_lock_its_key_forever(): void
    {
        $owner = $this->signedInOwner();

        /*
         | A claim left behind by a request that never unwound — the worker was
         | killed, or PHP hit its time limit. The client retries with the same
         | key, because that is what the key is for, so a permanently locked
         | claim would mean this memory can never be saved.
         */
        IdempotencyKey::create([
            'user_id' => $owner->id,
            'key' => 'interrupted',
            'endpoint' => 'memories.store',
            'request_hash' => str_repeat('a', 64),
            'status' => IdempotencyKey::STATUS_IN_PROGRESS,
            'expires_at' => now()->addDay(),
            'created_at' => now()->subHour(),
        ]);

        $this->postJson('/api/memories', [
            'title' => 'Saved On The Retry',
            'memory_date' => '2026-07-07',
            'uploads' => [$this->completeUpload($this->jpegBytes())],
        ], $this->idempotent('interrupted'))->assertCreated();

        $this->assertSame(1, Memory::query()->count());
    }

    public function test_a_save_genuinely_still_running_is_not_stolen(): void
    {
        $owner = $this->signedInOwner();

        // Claimed moments ago: another request really is mid-flight.
        IdempotencyKey::create([
            'user_id' => $owner->id,
            'key' => 'in-flight',
            'endpoint' => 'memories.store',
            'request_hash' => str_repeat('a', 64),
            'status' => IdempotencyKey::STATUS_IN_PROGRESS,
            'expires_at' => now()->addDay(),
        ]);

        $this->postJson('/api/memories', [
            'title' => 'Double Tap',
            'memory_date' => '2026-07-07',
            'uploads' => [$this->completeUpload($this->jpegBytes())],
        ], $this->idempotent('in-flight'))->assertStatus(409);

        $this->assertSame(0, Memory::query()->count());
    }

    public function test_files_are_taken_back_out_of_drive_when_the_database_write_fails(): void
    {
        $this->signedInOwner();

        /*
         | Force a clash on the unique Drive-file index: the upload succeeds,
         | the catalogue write does not. Without a compensating delete the
         | bytes would sit in Drive with nothing pointing at them.
         */
        $this->drive->fixedFileId = 'drive-collision';

        MemoryMedia::factory()
            ->for(Memory::factory()->for($this->owner()))
            ->create(['drive_file_id' => 'drive-collision']);

        $before = Memory::query()->count();

        $this->postJson('/api/memories', [
            'title' => 'Doomed',
            'memory_date' => '2026-06-06',
            'uploads' => [$this->completeUpload($this->jpegBytes())],
        ], $this->idempotent())
            ->assertStatus(422)
            ->assertJsonPath('retryable', true);

        $this->assertSame($before, Memory::query()->count());
        $this->assertContains('drive-collision', $this->drive->deleted);
    }

    public function test_a_full_drive_says_so_and_does_not_invite_a_pointless_retry(): void
    {
        $this->signedInOwner();

        $this->drive->failUploadAtCall = 1;
        $this->drive->uploadException = new GoogleDriveException(
            'The user has exceeded their Drive storage quota.',
            403,
            'storageQuotaExceeded',
        );

        $this->postJson('/api/memories', [
            'title' => 'No Room',
            'memory_date' => '2026-06-06',
            'uploads' => [$this->completeUpload($this->jpegBytes())],
        ], $this->idempotent())
            ->assertStatus(422)
            ->assertJsonPath('retryable', false)
            ->assertJsonFragment(['message' => "There's no space left in the connected Google Drive, so this memory wasn't saved. Free up some room and try again."]);

        $this->assertSame(0, Memory::query()->count());
    }

    public function test_media_can_be_added_to_an_existing_memory(): void
    {
        $this->signedInOwner();

        $this->postJson('/api/memories', [
            'title' => 'Growing',
            'memory_date' => '2026-01-01',
            'uploads' => [$this->completeUpload($this->jpegBytes(800, 600))],
        ], $this->idempotent('create'))->assertCreated();

        $memory = Memory::query()->firstOrFail();

        $this->postJson("/api/memories/{$memory->uuid}/media", [
            'uploads' => [$this->completeUpload($this->jpegBytes(640, 480))],
        ], $this->idempotent('attach'))->assertCreated()->assertJsonCount(2, 'data.media');

        $this->assertSame(2, $memory->fresh()->media_count);
        $this->assertSame([0, 1], $memory->media()->pluck('sort_order')->all());
    }

    public function test_the_same_photo_twice_in_one_memory_is_collapsed_to_one(): void
    {
        $this->signedInOwner();

        $bytes = $this->jpegBytes();

        $this->postJson('/api/memories', [
            'title' => 'Chosen Twice',
            'memory_date' => '2026-01-01',
            'uploads' => [
                $this->completeUpload($bytes, 'a.jpg'),
                $this->completeUpload($bytes, 'again.jpg'),
            ],
        ], $this->idempotent())->assertCreated()->assertJsonCount(1, 'data.media');

        $this->assertSame(1, $this->drive->uploadCount());
    }

    public function test_re_adding_a_photo_a_memory_already_has_is_explained_not_crashed(): void
    {
        $this->signedInOwner();

        $bytes = $this->jpegBytes();

        $this->postJson('/api/memories', [
            'title' => 'Already Here',
            'memory_date' => '2026-01-01',
            'uploads' => [$this->completeUpload($bytes, 'first.jpg')],
        ], $this->idempotent('create'))->assertCreated();

        $memory = Memory::query()->firstOrFail();

        $this->postJson("/api/memories/{$memory->uuid}/media", [
            'uploads' => [$this->completeUpload($bytes, 'same-again.jpg')],
        ], $this->idempotent('attach'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Those files are already part of this memory, or were removed from it.');

        $this->assertSame(1, $memory->fresh()->media_count);
    }

    public function test_a_memory_cannot_be_built_from_someone_elses_upload(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum');
        $strangersUpload = $this->completeUpload($this->jpegBytes());

        $this->signedInOwner();

        $this->postJson('/api/memories', [
            'title' => 'Not Mine',
            'memory_date' => '2026-01-01',
            'uploads' => [$strangersUpload],
        ], $this->idempotent())->assertStatus(422);

        $this->assertSame(0, Memory::query()->count());
    }

    public function test_an_unfinished_upload_cannot_become_a_memory(): void
    {
        $this->signedInOwner();

        $bytes = $this->jpegBytes();

        $open = $this->postJson('/api/uploads', [
            'file_name' => 'partial.jpg',
            'size' => strlen($bytes),
            'mime_type' => 'image/jpeg',
        ])->assertCreated();

        // Send only the first chunk, then try to use it.
        $this->sendChunk($open->json('data.id'), 0, substr($bytes, 0, (int) $open->json('data.chunk_size')));

        $this->postJson('/api/memories', [
            'title' => 'Incomplete',
            'memory_date' => '2026-01-01',
            'uploads' => [$open->json('data.id')],
        ], $this->idempotent())->assertStatus(422);

        $this->assertSame(0, $this->drive->uploadCount());
    }
}
