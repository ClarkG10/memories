<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Memory;
use App\Models\MemoryMedia;
use App\Services\GoogleDrive\GoogleDriveException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaServingTest extends TestCase
{
    use RefreshDatabase;

    private function media(array $attributes = []): MemoryMedia
    {
        $memory = Memory::factory()->for($this->owner())->create(['media_count' => 1]);

        return MemoryMedia::factory()->for($memory)->create($attributes);
    }

    public function test_a_photo_is_rendered_once_and_cached_for_every_request_after(): void
    {
        $media = $this->media();

        // A real JPEG standing in for the original held in Drive.
        $this->drive->files[$media->drive_file_id] = [
            'name' => 'photo.jpg',
            'folder' => 'f',
            'bytes' => 0,
        ];

        Storage::disk('derivatives')->put("{$media->uuid}/w640.jpg", $this->jpegBytes(640, 480));

        $response = $this->get("/api/media/{$media->uuid}/image?w=640");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $this->assertNotNull($response->headers->get('ETag'));
        $this->assertStringContainsString('max-age', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_public_archive_lets_photos_be_cached_hard_and_a_private_one_does_not(): void
    {
        $media = $this->media();
        Storage::disk('derivatives')->put("{$media->uuid}/w640.jpg", $this->jpegBytes(64, 64));

        // Symfony reorders the directives, so compare on content.
        $public = $this->get("/api/media/{$media->uuid}/image?w=640")->assertOk();
        $this->assertStringContainsString('public', (string) $public->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', (string) $public->headers->get('Cache-Control'));

        config(['memories.public' => false]);
        $this->signedInOwner();

        $private = $this->get("/api/media/{$media->uuid}/image?w=640")->assertOk();
        $this->assertStringContainsString('private', (string) $private->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $private->headers->get('Cache-Control'));
    }

    public function test_a_video_is_streamed_with_range_support_so_it_can_be_scrubbed(): void
    {
        $media = $this->media(['type' => MemoryMedia::TYPE_VIDEO, 'mime_type' => 'video/mp4']);

        $this->drive->files[$media->drive_file_id] = ['name' => 'clip.mp4', 'folder' => 'f', 'bytes' => 2048];

        $whole = $this->get("/api/media/{$media->uuid}/stream");
        $whole->assertOk();
        $whole->assertHeader('Accept-Ranges', 'bytes');
        $whole->assertHeader('Content-Type', 'video/mp4');

        $ranged = $this->call('GET', "/api/media/{$media->uuid}/stream", [], [], [], [
            'HTTP_RANGE' => 'bytes=0-1023',
        ]);

        $ranged->assertStatus(206);
        $this->assertNotNull($ranged->headers->get('Content-Range'));
    }

    public function test_asking_for_a_photo_as_a_video_or_the_reverse_is_a_dead_end(): void
    {
        $photo = $this->media();
        $video = $this->media(['type' => MemoryMedia::TYPE_VIDEO, 'mime_type' => 'video/mp4']);

        $this->get("/api/media/{$photo->uuid}/stream")->assertNotFound();
        $this->get("/api/media/{$video->uuid}/image")->assertNotFound();
    }

    public function test_a_video_without_a_poster_yet_says_so_instead_of_breaking(): void
    {
        $media = $this->media(['type' => MemoryMedia::TYPE_VIDEO, 'mime_type' => 'video/mp4']);

        // Drive has not finished generating a thumbnail.
        $this->get("/api/media/{$media->uuid}/poster")->assertNotFound();
    }

    public function test_a_private_archive_does_not_serve_media_to_strangers(): void
    {
        config(['memories.public' => false]);

        $media = $this->media();
        Storage::disk('derivatives')->put("{$media->uuid}/w640.jpg", $this->jpegBytes(64, 64));

        $this->get("/api/media/{$media->uuid}/image?w=640")->assertForbidden();

        $this->signedInOwner();
        $this->get("/api/media/{$media->uuid}/image?w=640")->assertOk();
    }

    public function test_a_private_archive_still_shows_its_photographs_through_signed_urls(): void
    {
        config(['memories.public' => false]);

        $owner = $this->signedInOwner();
        $memory = Memory::factory()->for($owner)->create(['media_count' => 1]);
        $media = MemoryMedia::factory()->for($memory)->create();

        Storage::disk('derivatives')->put("{$media->uuid}/w640.jpg", $this->jpegBytes(64, 64));

        $thumb = $this->getJson('/api/timeline')
            ->assertOk()
            ->json('data.0.preview.0.urls.thumb');

        $this->assertStringContainsString('signature=', (string) $thumb);

        /*
         | The browser fetches this from an <img> tag, which carries no token.
         | Without the signature a private archive would show nothing at all.
         */
        $this->app['auth']->forgetGuards();

        $this->get($thumb)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_signature_cannot_be_edited_into_a_url_for_another_file(): void
    {
        config(['memories.public' => false]);

        $owner = $this->signedInOwner();
        $memory = Memory::factory()->for($owner)->create(['media_count' => 2]);
        $first = MemoryMedia::factory()->for($memory)->create();
        $second = MemoryMedia::factory()->for($memory)->create();

        foreach ([$first, $second] as $item) {
            Storage::disk('derivatives')->put("{$item->uuid}/w640.jpg", $this->jpegBytes(64, 64));
        }

        $thumb = (string) $this->getJson('/api/timeline')->json('data.0.preview.0.urls.thumb');

        $this->app['auth']->forgetGuards();

        // Same signature, different file.
        $this->get(str_replace($first->uuid, $second->uuid, $thumb))->assertForbidden();

        // Same signature, larger rendition than was granted.
        $this->get(str_replace('w=640', 'w=2400', $thumb))->assertForbidden();
    }

    public function test_a_public_archive_keeps_plain_cacheable_urls(): void
    {
        $owner = $this->owner();
        $memory = Memory::factory()->for($owner)->create(['media_count' => 1]);
        MemoryMedia::factory()->for($memory)->create();

        $thumb = (string) $this->getJson('/api/timeline')->json('data.0.preview.0.urls.thumb');

        // No signature to expire, so the browser can hold on to it for a year.
        $this->assertStringNotContainsString('signature=', $thumb);
    }

    public function test_an_unknown_media_id_is_a_plain_not_found(): void
    {
        $this->get('/api/media/'.fake()->uuid().'/image')->assertNotFound();
    }

    public function test_a_photograph_is_pulled_from_drive_once_and_then_never_again(): void
    {
        $media = $this->media();

        // Narrower than the widest rung, which is the case that used to leave
        // the largest renditions permanently missing.
        $this->drive->files[$media->drive_file_id] = [
            'name' => 'photo.jpg',
            'folder' => 'f',
            'bytes' => 0,
            'contents' => $this->jpegBytes(900, 600),
        ];

        $this->get("/api/media/{$media->uuid}/image?w=2400")->assertOk();

        $this->assertSame(1, $this->drive->downloads);

        /*
         | Every rung must now exist, including those wider than the original.
         | A missing rung is a cache miss, and a cache miss here means fetching
         | the original from Drive again — on every full-size view, forever.
         */
        foreach ([640, 1600, 2400] as $width) {
            $this->assertTrue(
                Storage::disk('derivatives')->exists("{$media->uuid}/w{$width}.jpg"),
                "The w{$width} rendition was never written.",
            );
        }

        foreach ([640, 1600, 2400, 2400, 1600] as $width) {
            $this->get("/api/media/{$media->uuid}/image?w={$width}")->assertOk();
        }

        $this->assertSame(1, $this->drive->downloads, 'Drive was asked for the original again.');
    }

    public function test_a_drive_outage_reads_as_a_temporary_failure_not_a_missing_photograph(): void
    {
        $media = $this->media();

        $this->drive->files[$media->drive_file_id] = ['name' => 'p.jpg', 'folder' => 'f', 'bytes' => 10];
        $this->drive->downloadException = new GoogleDriveException(
            'Drive is unavailable.',
            503,
        );

        /*
         | 404 would tell the browser, and anyone watching the logs, that these
         | photographs are gone. They are not — Drive is simply unreachable,
         | and that is worth being able to tell apart at 2am.
         */
        $this->get("/api/media/{$media->uuid}/image?w=640")->assertStatus(503);
    }

    public function test_no_half_written_rendition_is_left_behind(): void
    {
        $media = $this->media();

        $this->drive->files[$media->drive_file_id] = [
            'name' => 'photo.jpg',
            'folder' => 'f',
            'bytes' => 0,
            'contents' => $this->jpegBytes(2000, 1500),
        ];

        $this->get("/api/media/{$media->uuid}/image?w=640")->assertOk();

        // Renditions are renamed into place, so no temporary file survives and
        // nothing can be served half-written under an immutable cache header.
        foreach (Storage::disk('derivatives')->allFiles($media->uuid) as $file) {
            $this->assertStringEndsWith('.jpg', $file);
            $this->assertStringNotContainsString('.tmp', $file);
        }
    }
}
