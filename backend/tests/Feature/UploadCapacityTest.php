<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Being told there was never any room, before spending an hour finding out.
 *
 * A file travels browser → this server's disk → Drive, and either can run out
 * first. The configured maximum is a stated rule; these are the physical
 * truths behind it, and the point of checking them here is that the answer
 * arrives before the first byte is sent rather than after the last one.
 */
class UploadCapacityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The quota is cached for a minute in real use; each test starts fresh.
        Cache::forget('drive:quota');
    }

    private function open(int $size): TestResponse
    {
        return $this->postJson('/api/uploads', [
            'file_name' => 'holiday.mp4',
            'size' => $size,
        ]);
    }

    public function test_a_file_that_fits_is_accepted(): void
    {
        $this->signedInOwner();

        $this->open(10 * 1024 * 1024)->assertCreated();
    }

    public function test_a_file_larger_than_the_drive_has_room_for_is_refused_up_front(): void
    {
        $this->signedInOwner();

        // 1 GB of a 15 GB account left.
        $this->drive->quotaLimit = 15_000_000_000;
        $this->drive->quotaUsage = 14_000_000_000;

        $response = $this->open(3 * 1024 * 1024 * 1024)->assertStatus(422);

        $message = $response->json('errors.size.0');

        // Both figures, so the answer is actionable rather than merely a no:
        // what is left, and how big the thing that will not fit is.
        $this->assertStringContainsString('Google Drive', $message);
        $this->assertStringContainsString('954 MB left', $message);
        $this->assertStringContainsString('3 GB', $message);
    }

    public function test_a_file_that_would_fill_the_server_disk_is_refused_up_front(): void
    {
        $this->signedInOwner();

        /*
         | An impossible headroom stands in for a nearly-full disk, which is
         | the one thing here that cannot be faked: the check is real, and it
         | is being asked to reserve more room than any disk has.
         */
        config(['memories.uploads.disk_headroom_bytes' => 900 * 1024 ** 4]);

        $response = $this->open(5 * 1024 * 1024)->assertStatus(422);

        $this->assertStringContainsString(
            'not enough room on the server',
            (string) $response->json('errors.size.0'),
        );
    }

    public function test_a_drive_that_cannot_be_asked_is_not_treated_as_full(): void
    {
        $this->signedInOwner();

        // A Workspace account with pooled storage reports no limit at all.
        $this->drive->quotaLimit = null;

        // Not knowing must never become a refusal: that would take the archive
        // offline for writes every time Drive had a bad minute.
        $this->open(50 * 1024 * 1024)->assertCreated();
    }

    public function test_a_file_over_the_stated_maximum_says_both_numbers(): void
    {
        $this->signedInOwner();

        config(['memories.uploads.max_bytes.video' => 100 * 1024 * 1024]);
        config(['memories.uploads.max_bytes.image' => 10 * 1024 * 1024]);

        $message = (string) $this->open(500 * 1024 * 1024)->assertStatus(422)->json('errors.size.0');

        $this->assertStringContainsString('500 MB', $message);
        $this->assertStringContainsString('100 MB', $message);
    }

    public function test_the_quota_is_asked_for_once_rather_than_once_per_file(): void
    {
        $this->signedInOwner();

        for ($i = 0; $i < 5; $i++) {
            $this->open(1024 * 1024)->assertCreated();
        }

        // Uploading forty files should not make forty calls to Drive.
        $this->assertLessThanOrEqual(1, $this->drive->aboutCalls);
    }
}
