<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use App\Services\GoogleDrive\GoogleDriveService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeGoogleDrive;

abstract class TestCase extends BaseTestCase
{
    protected FakeGoogleDrive $drive;

    protected function setUp(): void
    {
        parent::setUp();

        // No test is ever allowed to reach the real Drive.
        $this->drive = new FakeGoogleDrive;
        $this->app->instance(GoogleDriveService::class, $this->drive);

        Storage::fake('uploads');
        Storage::fake('derivatives');
    }

    /**
     * The archive has exactly one owner, so asking for them twice in a test
     * must return the same person rather than colliding on the email.
     */
    protected function owner(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'owner@example.test'],
            User::factory()->raw(['name' => 'Archive Owner', 'email' => 'owner@example.test']),
        );
    }

    protected function signedInOwner(): User
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum');

        return $owner;
    }

    /**
     * Walk a file through the real chunked upload endpoints and return the
     * finished session's id.
     *
     * Tests that create memories go through the same path a browser does, so
     * the chunking, offsets and validation are all exercised rather than
     * stubbed around.
     */
    protected function completeUpload(string $bytes, string $fileName = 'photo.jpg', ?string $mimeType = 'image/jpeg'): string
    {
        $open = $this->postJson('/api/uploads', [
            'file_name' => $fileName,
            'size' => strlen($bytes),
            'mime_type' => $mimeType,
        ])->assertCreated();

        $id = $open->json('data.id');
        $chunkSize = (int) $open->json('data.chunk_size');
        $total = (int) $open->json('data.total_chunks');

        for ($index = 0; $index < $total; $index++) {
            $this->sendChunk($id, $index, substr($bytes, $index * $chunkSize, $chunkSize))
                ->assertOk();
        }

        $this->postJson("/api/uploads/{$id}/complete")->assertOk();

        return $id;
    }

    /**
     * Chunks are sent as a raw body, so they cannot go through postJson.
     */
    protected function sendChunk(string $sessionId, int $index, string $bytes): TestResponse
    {
        return $this->call(
            'PUT',
            "/api/uploads/{$sessionId}/chunks/{$index}",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'],
            $bytes,
        );
    }

    /**
     * A real JPEG, so MIME sniffing and dimension reading have something
     * genuine to work with.
     */
    protected function jpegBytes(int $width = 800, int $height = 600): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 120, 170, 220));

        ob_start();
        imagejpeg($image, null, 80);

        return (string) ob_get_clean();
    }

    /**
     * @return array<string, string>
     */
    protected function idempotent(string $key = 'test-key-1'): array
    {
        return ['Idempotency-Key' => $key];
    }
}
