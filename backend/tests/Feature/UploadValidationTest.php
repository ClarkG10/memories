<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_file_is_judged_by_its_bytes_not_by_what_the_browser_claims(): void
    {
        $this->signedInOwner();

        $bytes = "#!/bin/sh\necho not a photo\n";

        $open = $this->postJson('/api/uploads', [
            'file_name' => 'holiday.jpg',
            'size' => strlen($bytes),
            // A convincing lie.
            'mime_type' => 'image/jpeg',
        ])->assertCreated();

        $this->sendChunk($open->json('data.id'), 0, $bytes)->assertOk();

        $this->postJson("/api/uploads/{$open->json('data.id')}/complete")
            ->assertStatus(422)
            ->assertJsonPath('retryable', false);

        $this->assertSame(
            UploadSession::STATUS_FAILED,
            UploadSession::query()->firstOrFail()->status,
        );
    }

    public function test_an_upload_larger_than_the_archive_accepts_is_refused_before_a_byte_arrives(): void
    {
        $this->signedInOwner();

        $this->postJson('/api/uploads', [
            'file_name' => 'enormous.mp4',
            'size' => (int) config('memories.uploads.max_bytes.video') + 1,
            'mime_type' => 'video/mp4',
        ])->assertStatus(422)->assertJsonValidationErrors('size');

        $this->assertSame(0, UploadSession::query()->count());
    }

    public function test_an_image_over_the_image_limit_is_refused_once_its_type_is_known(): void
    {
        config(['memories.uploads.max_bytes.image' => 1024]);

        $this->signedInOwner();

        $bytes = $this->jpegBytes(800, 600);
        $this->assertGreaterThan(1024, strlen($bytes));

        $open = $this->postJson('/api/uploads', [
            'file_name' => 'big.jpg',
            'size' => strlen($bytes),
            'mime_type' => 'image/jpeg',
        ])->assertCreated();

        $chunkSize = (int) $open->json('data.chunk_size');

        for ($i = 0; $i < (int) $open->json('data.total_chunks'); $i++) {
            $this->sendChunk($open->json('data.id'), $i, substr($bytes, $i * $chunkSize, $chunkSize));
        }

        $this->postJson("/api/uploads/{$open->json('data.id')}/complete")
            ->assertStatus(422)
            ->assertJsonFragment(['retryable' => false]);
    }

    public function test_an_upload_missing_a_chunk_cannot_be_completed(): void
    {
        $this->signedInOwner();

        $bytes = $this->jpegBytes();

        $open = $this->postJson('/api/uploads', [
            'file_name' => 'photo.jpg',
            'size' => strlen($bytes),
        ])->assertCreated();

        $chunkSize = (int) $open->json('data.chunk_size');
        $total = (int) $open->json('data.total_chunks');
        $this->assertGreaterThan(2, $total, 'This test needs a file that spans several chunks.');

        // Everything except the second piece.
        for ($i = 0; $i < $total; $i++) {
            if ($i === 1) {
                continue;
            }

            $this->sendChunk($open->json('data.id'), $i, substr($bytes, $i * $chunkSize, $chunkSize));
        }

        $this->postJson("/api/uploads/{$open->json('data.id')}/complete")
            ->assertStatus(422)
            ->assertJsonValidationErrors('upload');
    }

    public function test_the_server_reports_which_pieces_are_still_missing_so_an_upload_can_resume(): void
    {
        $this->signedInOwner();

        $bytes = $this->jpegBytes();

        $open = $this->postJson('/api/uploads', [
            'file_name' => 'photo.jpg',
            'size' => strlen($bytes),
        ])->assertCreated();

        $chunkSize = (int) $open->json('data.chunk_size');

        $response = $this->sendChunk($open->json('data.id'), 0, substr($bytes, 0, $chunkSize))->assertOk();

        $this->assertSame(1, $response->json('data.received_chunks'));
        $this->assertNotContains(0, $response->json('data.missing_chunks'));
        $this->assertContains(1, $response->json('data.missing_chunks'));
    }

    public function test_re_sending_a_chunk_is_harmless(): void
    {
        $this->signedInOwner();

        $bytes = $this->jpegBytes();

        $open = $this->postJson('/api/uploads', [
            'file_name' => 'photo.jpg',
            'size' => strlen($bytes),
        ])->assertCreated();

        $chunkSize = (int) $open->json('data.chunk_size');

        $this->sendChunk($open->json('data.id'), 0, substr($bytes, 0, $chunkSize))->assertOk();
        $second = $this->sendChunk($open->json('data.id'), 0, substr($bytes, 0, $chunkSize))->assertOk();

        $this->assertSame(1, $second->json('data.received_chunks'));
    }

    public function test_a_chunk_beyond_the_end_of_the_file_is_rejected(): void
    {
        $this->signedInOwner();

        $open = $this->postJson('/api/uploads', [
            'file_name' => 'photo.jpg',
            'size' => 512,
        ])->assertCreated();

        $this->sendChunk($open->json('data.id'), 99, 'stray bytes')
            ->assertStatus(422)
            ->assertJsonValidationErrors('index');
    }

    public function test_uploads_belong_to_the_person_who_opened_them(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum');
        $open = $this->postJson('/api/uploads', ['file_name' => 'x.jpg', 'size' => 100])->assertCreated();

        $this->signedInOwner();

        $this->sendChunk($open->json('data.id'), 0, 'abc')->assertNotFound();
        $this->postJson("/api/uploads/{$open->json('data.id')}/complete")->assertNotFound();
    }

    public function test_uploading_requires_signing_in(): void
    {
        $this->postJson('/api/uploads', ['file_name' => 'x.jpg', 'size' => 100])
            ->assertUnauthorized();
    }

    public function test_an_abandoned_upload_is_swept_off_the_disk(): void
    {
        $this->signedInOwner();

        $open = $this->postJson('/api/uploads', ['file_name' => 'x.jpg', 'size' => 4096])->assertCreated();
        $this->sendChunk($open->json('data.id'), 0, str_repeat('a', 1024))->assertOk();

        $session = UploadSession::query()->firstOrFail();
        $this->assertTrue(Storage::disk('uploads')->exists($session->path));

        $session->forceFill(['expires_at' => now()->subHour()])->save();

        $this->artisan('uploads:prune')->assertSuccessful();

        $this->assertFalse(Storage::disk('uploads')->exists($session->path));
        $this->assertSame(UploadSession::STATUS_EXPIRED, $session->fresh()->status);
    }

    public function test_discarding_an_upload_removes_its_temporary_file(): void
    {
        $this->signedInOwner();

        $open = $this->postJson('/api/uploads', ['file_name' => 'x.jpg', 'size' => 4096])->assertCreated();
        $this->sendChunk($open->json('data.id'), 0, str_repeat('a', 1024))->assertOk();

        $session = UploadSession::query()->firstOrFail();

        $this->deleteJson("/api/uploads/{$open->json('data.id')}")->assertOk();

        $this->assertFalse(Storage::disk('uploads')->exists($session->path));
    }

    public function test_an_interrupted_upload_reports_what_is_left_so_it_can_resume(): void
    {
        $this->signedInOwner();

        $bytes = $this->jpegBytes();

        $open = $this->postJson('/api/uploads', [
            'file_name' => 'photo.jpg',
            'size' => strlen($bytes),
        ])->assertCreated();

        $id = $open->json('data.id');
        $chunkSize = (int) $open->json('data.chunk_size');

        // The connection dies after the first piece.
        $this->sendChunk($id, 0, substr($bytes, 0, $chunkSize))->assertOk();

        /*
         | Reading the session back is what turns a retry into a resume: the
         | browser sends only the missing pieces instead of pushing a whole
         | video up the wire a second time.
         */
        $resumed = $this->getJson("/api/uploads/{$id}")->assertOk();

        $this->assertSame(1, $resumed->json('data.received_chunks'));
        $this->assertNotContains(0, $resumed->json('data.missing_chunks'));
        $this->assertContains(1, $resumed->json('data.missing_chunks'));

        foreach ($resumed->json('data.missing_chunks') as $index) {
            $this->sendChunk($id, $index, substr($bytes, $index * $chunkSize, $chunkSize))->assertOk();
        }

        $this->postJson("/api/uploads/{$id}/complete")->assertOk();
    }

    public function test_someone_elses_upload_cannot_be_inspected(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum');
        $open = $this->postJson('/api/uploads', ['file_name' => 'x.jpg', 'size' => 100])->assertCreated();

        $this->signedInOwner();

        $this->getJson("/api/uploads/{$open->json('data.id')}")->assertNotFound();
    }
}
