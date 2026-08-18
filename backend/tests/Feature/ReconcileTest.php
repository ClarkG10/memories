<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Memory;
use App\Models\MemoryMedia;
use App\Services\GoogleDrive\GoogleDriveException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The command that answers "Drive has a folder the archive does not".
 *
 * Both sides can hold something the other does not know about, and neither
 * shows up anywhere a person would look. This is the only place that says so.
 */
class ReconcileTest extends TestCase
{
    use RefreshDatabase;

    /** Put a file in the fake Drive and, optionally, a memory that claims it. */
    private function driveFile(string $id, string $name, string $folder = 'folder-image-2025-11'): void
    {
        $this->drive->files[$id] = ['name' => $name, 'folder' => $folder, 'bytes' => 2048];
    }

    private function claimed(string $driveId, string $state = MemoryMedia::DELETION_ACTIVE): MemoryMedia
    {
        $memory = Memory::factory()->for($this->owner())->create(['title' => 'A day out']);

        return MemoryMedia::factory()->for($memory)->create([
            'drive_file_id' => $driveId,
            'deletion_state' => $state,
        ]);
    }

    public function test_it_is_quiet_when_both_sides_agree(): void
    {
        $this->driveFile('drive-1', '2025-11-23 A day out 01.jpg');
        $this->claimed('drive-1');

        $this->artisan('memories:reconcile')
            ->expectsOutputToContain('Every file in Drive belongs to a memory')
            ->assertSuccessful();
    }

    public function test_it_finds_files_no_memory_claims(): void
    {
        $this->driveFile('drive-1', '2025-11-23 Read this to me 01.jpg');
        $this->driveFile('drive-2', '2025-11-23 Read this to me 02.jpg');

        /*
         | Exactly the reported situation: files sitting in a 2025 folder that
         | the archive has no row for, so the year is invisible in the app
         | while Drive plainly shows it.
         */
        $this->artisan('memories:reconcile')
            ->expectsOutputToContain('2 file(s) in Drive belong to no memory')
            ->assertFailed();
    }

    public function test_a_memory_awaiting_its_deletion_still_owns_its_files(): void
    {
        $this->driveFile('drive-1', '2025-11-23 A day out 01.jpg');
        $this->claimed('drive-1', MemoryMedia::DELETION_DELETING);

        // Counting these as orphans would report a problem during ordinary use,
        // which is how a health check gets ignored.
        $this->artisan('memories:reconcile')
            ->expectsOutputToContain('Every file in Drive belongs to a memory')
            ->assertSuccessful();
    }

    public function test_it_finds_photographs_whose_file_has_gone(): void
    {
        // Claimed, but never put in Drive: the broken-photograph case.
        $this->claimed('drive-vanished');

        $this->artisan('memories:reconcile')
            ->expectsOutputToContain('point at a Drive file that is not there')
            ->assertFailed();
    }

    public function test_it_can_be_narrowed_to_one_year(): void
    {
        $this->driveFile('drive-1', '2025-11-23 Read this to me 01.jpg');
        $this->driveFile('drive-2', '2026-08-10 Something else 01.jpg');

        $this->artisan('memories:reconcile --year=2025')
            ->expectsOutputToContain('1 file(s) in Drive belong to no memory')
            ->assertFailed();
    }

    public function test_it_says_so_plainly_when_drive_cannot_be_read(): void
    {
        $this->drive->listException = new GoogleDriveException('Drive is unavailable.', 503);

        $this->artisan('memories:reconcile')
            ->expectsOutputToContain('Could not read Drive')
            ->assertFailed();
    }
}
