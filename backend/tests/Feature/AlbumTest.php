<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Memory;
use App\Services\GoogleDrive\GoogleDriveException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AlbumTest extends TestCase
{
    use RefreshDatabase;

    private function save(array $overrides = [], string $key = 'k1'): TestResponse
    {
        return $this->postJson('/api/memories', array_merge([
            'title' => 'A Day',
            'memory_date' => '2026-08-10',
            'uploads' => [$this->completeUpload($this->jpegBytes())],
        ], $overrides), $this->idempotent($key));
    }

    public function test_without_an_album_files_still_go_to_the_date_folders(): void
    {
        $this->signedInOwner();

        $this->save()->assertCreated();

        $this->assertSame('folder-image-2026-08', Memory::first()->media()->first()->drive_folder_id);
        $this->assertNull(Memory::first()->album);
    }

    public function test_an_album_sends_the_files_to_their_own_folder_instead(): void
    {
        $this->signedInOwner();

        $this->save(['album' => 'Our Wedding'])->assertCreated()
            ->assertJsonPath('data.album', 'Our Wedding');

        $memory = Memory::first();

        $this->assertSame('Our Wedding', $memory->album);
        $this->assertSame('folder-album-our-wedding', $memory->media()->first()->drive_folder_id);
    }

    public function test_photos_and_videos_in_an_album_are_filed_together(): void
    {
        $this->signedInOwner();

        // Two different files so nothing is de-duplicated.
        $this->save([
            'album' => 'Japan',
            'uploads' => [
                $this->completeUpload($this->jpegBytes(800, 600)),
                $this->completeUpload($this->jpegBytes(640, 480)),
            ],
        ])->assertCreated();

        $folders = Memory::first()->media()->pluck('drive_folder_id')->unique();

        // The whole point of naming an album: one folder, not split by type.
        $this->assertCount(1, $folders);
        $this->assertSame('folder-album-japan', $folders->first());
    }

    public function test_an_album_name_cannot_smuggle_a_path_into_drive(): void
    {
        $this->signedInOwner();

        $this->save(['album' => '../../etc/  Wedding /'])->assertCreated();

        /*
         | The name becomes a Drive folder name, so anything that would change
         | the meaning of a path is stripped rather than rejected — nobody
         | naming an album should have to think about filesystems.
         */
        $this->assertSame('etc  Wedding', Memory::first()->album);
    }

    public function test_an_empty_album_name_is_treated_as_none(): void
    {
        $this->signedInOwner();

        $this->save(['album' => '   '])->assertCreated();

        $this->assertNull(Memory::first()->album);
        $this->assertSame('folder-image-2026-08', Memory::first()->media()->first()->drive_folder_id);
    }

    public function test_albums_already_used_are_offered_back_most_recent_first(): void
    {
        $owner = $this->owner();

        Memory::factory()->for($owner)->on('2024-01-01')->create(['album' => 'Old Trip']);
        Memory::factory()->for($owner)->on('2026-05-05')->create(['album' => 'Our Wedding']);
        Memory::factory()->for($owner)->on('2025-01-01')->create(['album' => null]);

        $this->getJson('/api/albums')
            ->assertOk()
            ->assertExactJson(['data' => ['Our Wedding', 'Old Trip']]);
    }

    public function test_the_album_list_keeps_up_when_a_memory_is_added(): void
    {
        $this->signedInOwner();

        $this->getJson('/api/albums')->assertOk()->assertJsonPath('data', []);

        $this->save(['album' => 'Japan'])->assertCreated();

        $this->getJson('/api/albums')->assertOk()->assertExactJson(['data' => ['Japan']]);
    }

    public function test_moving_a_memory_into_an_album_moves_its_files_in_drive(): void
    {
        $this->signedInOwner();

        $this->save()->assertCreated();

        $memory = Memory::first();
        $media = $memory->media()->first();

        $this->assertSame('folder-image-2026-08', $media->drive_folder_id);

        $this->patchJson("/api/memories/{$memory->uuid}", ['album' => 'Our Wedding'])->assertOk();

        /*
         | The folder is derived from the album, so the label alone is not
         | enough — leaving the file where it was would mean the archive says
         | one thing and the Drive folder tree says another.
         */
        $this->assertSame('folder-album-our-wedding', $this->drive->moved[$media->drive_file_id] ?? null);
        $this->assertSame('folder-album-our-wedding', $media->fresh()->drive_folder_id);
    }

    public function test_changing_the_date_refiles_a_memory_that_has_no_album(): void
    {
        $this->signedInOwner();

        $this->save()->assertCreated();

        $memory = Memory::first();
        $media = $memory->media()->first();

        $this->patchJson("/api/memories/{$memory->uuid}", ['memory_date' => '2024-03-02'])->assertOk();

        $this->assertSame('folder-image-2024-03', $media->fresh()->drive_folder_id);
    }

    public function test_editing_something_else_leaves_the_files_alone(): void
    {
        $this->signedInOwner();

        $this->save(['album' => 'Japan'])->assertCreated();

        $memory = Memory::first();

        $this->patchJson("/api/memories/{$memory->uuid}", ['title' => 'A Better Title'])->assertOk();

        // Nothing about where it belongs changed, so nothing moves.
        $this->assertSame([], $this->drive->moved);
    }

    public function test_a_drive_that_will_not_move_a_file_does_not_fail_the_edit(): void
    {
        $this->signedInOwner();

        $this->save()->assertCreated();
        $memory = Memory::first();

        $this->drive->moveException = new GoogleDriveException(
            'Drive is unavailable.',
            503,
        );

        /*
         | Every lookup in this archive is by Drive id, never by folder, so a
         | failed move is untidy rather than lost — and must not turn a
         | successful edit into an error for the person who made it.
         */
        $this->patchJson("/api/memories/{$memory->uuid}", ['album' => 'Japan'])
            ->assertOk()
            ->assertJsonPath('data.album', 'Japan');

        $this->assertSame('folder-image-2026-08', $memory->media()->first()->drive_folder_id);
    }
}
