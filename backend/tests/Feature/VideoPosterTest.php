<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Memory;
use App\Models\MemoryMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A video needs a still before anyone presses play.
 *
 * Google produces one, but only after it has finished processing the upload —
 * which can be minutes. In the meantime the browser sends a frame it grabbed
 * while previewing the file, so a video is never just a play button on an
 * empty rectangle.
 */
class VideoPosterTest extends TestCase
{
    use RefreshDatabase;

    private function posterDataUri(int $w = 1280, int $h = 720): string
    {
        return 'data:image/jpeg;base64,'.base64_encode($this->jpegBytes($w, $h));
    }

    /** A file whose bytes really are an MP4, so type sniffing accepts it. */
    private function mp4Bytes(): string
    {
        return "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2".str_repeat("\x00", 2048);
    }

    private function uploadVideo(?string $poster): string
    {
        $bytes = $this->mp4Bytes();

        $open = $this->postJson('/api/uploads', [
            'file_name' => 'clip.mp4',
            'size' => strlen($bytes),
            'mime_type' => 'video/mp4',
        ])->assertCreated();

        $id = $open->json('data.id');
        $chunkSize = (int) $open->json('data.chunk_size');

        for ($i = 0; $i < (int) $open->json('data.total_chunks'); $i++) {
            $this->sendChunk($id, $i, substr($bytes, $i * $chunkSize, $chunkSize))->assertOk();
        }

        $this->postJson("/api/uploads/{$id}/complete", ['poster' => $poster])->assertOk();

        return $id;
    }

    private function saveMemory(string $upload, string $key = 'poster-key'): void
    {
        $this->postJson('/api/memories', [
            'title' => 'Our First Dance',
            'memory_date' => '2026-08-10',
            'uploads' => [$upload],
        ], $this->idempotent($key))->assertCreated();
    }

    public function test_a_video_has_a_poster_the_moment_it_is_saved(): void
    {
        $this->signedInOwner();

        $this->saveMemory($this->uploadVideo($this->posterDataUri()));

        $media = MemoryMedia::firstOrFail();
        $this->assertSame(MemoryMedia::TYPE_VIDEO, $media->type);

        /*
         | Served straight from disk, without ever asking Drive — which at this
         | point has almost certainly not finished generating one of its own.
         */
        $this->get("/api/media/{$media->uuid}/poster")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->assertSame(0, $this->drive->downloads);
    }

    public function test_the_captured_frame_also_becomes_the_blur_up_placeholder(): void
    {
        $this->signedInOwner();

        $this->saveMemory($this->uploadVideo($this->posterDataUri()));

        $placeholder = (string) MemoryMedia::firstOrFail()->placeholder;

        $this->assertStringStartsWith('data:image/jpeg;base64,', $placeholder);
        $this->assertLessThan(3000, strlen($placeholder));
    }

    public function test_a_video_without_a_captured_frame_falls_back_to_drive(): void
    {
        $this->signedInOwner();

        // Some phone codecs cannot be decoded by the browser at all, so no
        // frame is sent. Drive's own thumbnail is then the only source.
        $this->saveMemory($this->uploadVideo(null));

        $media = MemoryMedia::firstOrFail();

        $this->assertNull($media->placeholder, 'No frame was captured, so there is nothing to blur up.');

        /*
         | The still now comes from Drive rather than from the browser, and it
         | is fetched when the memory is saved rather than when someone first
         | looks at it — so by the time anyone asks, it is already on disk.
         */
        $this->assertTrue(Storage::disk('derivatives')->exists("{$media->uuid}/poster.jpg"));

        $this->get("/api/media/{$media->uuid}/poster")->assertOk();
    }

    public function test_anything_that_is_not_an_image_is_ignored_rather_than_stored(): void
    {
        $this->signedInOwner();

        $hostiles = [
            'data:image/jpeg;base64,'.base64_encode('<?php echo "not an image"; ?>'),
            'data:text/html;base64,'.base64_encode('<script>alert(1)</script>'),
            'javascript:alert(1)',
            'data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"/>'),
            'not-a-data-uri-at-all',
        ];

        foreach ($hostiles as $index => $hostile) {
            MemoryMedia::query()->forceDelete();
            Memory::query()->forceDelete();

            // A fresh key each time: the same one would replay the first save.
            $this->saveMemory($this->uploadVideo($hostile), "hostile-{$index}");

            $media = MemoryMedia::firstOrFail();

            $this->assertNull($media->placeholder, "Stored a placeholder for: {$hostile}");

            /*
             | A poster does end up on disk, because a video with no usable
             | frame falls back to Drive's own. What matters is whose bytes
             | they are: the hostile payload must never be what gets written
             | and later served back as an image.
             */
            $stored = Storage::disk('derivatives')->exists("{$media->uuid}/poster.jpg")
                ? Storage::disk('derivatives')->get("{$media->uuid}/poster.jpg")
                : null;

            if ($stored !== null) {
                $this->assertSame('thumbnail-bytes', $stored, "Served back what was sent: {$hostile}");
            }
        }
    }

    public function test_an_enormous_payload_is_refused_before_it_is_decoded(): void
    {
        $this->signedInOwner();

        // Larger than the ceiling: decoding is where the cost is, so the size
        // is checked first.
        $huge = 'data:image/jpeg;base64,'.str_repeat('A', 5 * 1024 * 1024);

        $this->saveMemory($this->uploadVideo($huge));

        $this->assertNull(MemoryMedia::firstOrFail()->placeholder);
    }
}
