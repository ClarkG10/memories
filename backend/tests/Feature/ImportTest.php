<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Memory;
use App\Models\MemoryMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Getting back photographs that only exist in Drive.
 *
 * When a save dies after the upload, the files are in the account under the
 * right name in the right folder, and nothing points at them. The obvious
 * remedy — delete and upload again — is the one that can lose them, because at
 * that point Drive is where they live. This builds the memory around them.
 */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    private function orphan(string $id, string $name, string $folder = 'folder-image-2025-11'): void
    {
        $this->drive->files[$id] = ['name' => $name, 'folder' => $folder, 'bytes' => 4096];
    }

    private function aSetOfEight(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->orphan(
                "drive-{$i}",
                sprintf('2025-11-23 Read this to remember %02d.jpg', $i),
            );
        }
    }

    public function test_it_rebuilds_a_memory_around_files_already_in_drive(): void
    {
        $this->owner();
        $this->aSetOfEight();

        $this->artisan('memories:import --no-interaction')
            ->expectsOutputToContain('Recovered "Read this to remember" with 8 file(s)')
            ->assertSuccessful();

        $memory = Memory::firstOrFail();

        // Both taken from the names the archive gave the files when it saved
        // them, so nobody has to remember what it was called.
        $this->assertSame('Read this to remember', $memory->title);
        $this->assertSame('2025-11-23', $memory->memory_date->toDateString());
        $this->assertSame(8, $memory->media()->count());
        $this->assertSame(8, (int) $memory->media_count);
    }

    public function test_the_year_reappears_in_the_archive(): void
    {
        $this->owner();
        $this->aSetOfEight();

        $this->assertSame([], $this->getJson('/api/timeline/years')->json('data'));

        $this->artisan('memories:import --no-interaction')->assertSuccessful();

        // The whole point: the year that was in Drive and nowhere else.
        $this->assertSame(
            [['year' => 2025, 'count' => 1]],
            $this->getJson('/api/timeline/years')->json('data'),
        );
    }

    public function test_it_points_at_the_existing_files_rather_than_copying_them(): void
    {
        $this->owner();
        $this->aSetOfEight();

        $before = $this->drive->uploadCount();

        $this->artisan('memories:import --no-interaction')->assertSuccessful();

        /*
         | Nothing is uploaded and nothing is moved: the rows are written to
         | point at files that are already where they belong. Reads are a
         | different matter — warming pulls each original down once to build
         | the sizes the timeline shows, which is the whole point of it.
         */
        $this->assertSame($before, $this->drive->uploadCount());
        $this->assertSame([], $this->drive->moved);

        $this->assertEqualsCanonicalizing(
            array_keys($this->drive->files),
            MemoryMedia::query()->pluck('drive_file_id')->all(),
        );
    }

    public function test_the_order_they_were_saved_in_is_the_order_they_come_back(): void
    {
        $this->owner();
        $this->aSetOfEight();

        $this->artisan('memories:import --no-interaction')->assertSuccessful();

        $names = MemoryMedia::query()->orderBy('sort_order')->pluck('file_name')->all();

        $this->assertSame('2025-11-23 Read this to remember 01.jpg', $names[0]);
        $this->assertSame('2025-11-23 Read this to remember 08.jpg', $names[7]);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->owner();
        $this->aSetOfEight();

        $this->artisan('memories:import --dry-run --no-interaction')
            ->expectsOutputToContain('nothing was changed')
            ->assertSuccessful();

        $this->assertSame(0, Memory::query()->count());
    }

    public function test_a_title_and_date_can_be_given_when_the_name_says_nothing(): void
    {
        $this->owner();
        $this->orphan('drive-1', 'IMG_4021.jpg');

        $this->artisan('memories:import --no-interaction --title="A day at the bay" --date=2025-07-04')
            ->assertSuccessful();

        $memory = Memory::firstOrFail();

        $this->assertSame('A day at the bay', $memory->title);
        $this->assertSame('2025-07-04', $memory->memory_date->toDateString());
    }

    public function test_files_a_memory_already_claims_are_left_alone(): void
    {
        $owner = $this->owner();
        $this->aSetOfEight();

        $existing = Memory::factory()->for($owner)->create();
        MemoryMedia::factory()->for($existing)->create(['drive_file_id' => 'drive-1']);

        $this->artisan('memories:import --no-interaction')->assertSuccessful();

        // Seven, not eight: the claimed one is not an orphan.
        $recovered = Memory::query()->where('id', '!=', $existing->id)->firstOrFail();

        $this->assertSame(7, $recovered->media()->count());
    }

    public function test_the_same_photograph_uploaded_twice_comes_back_once(): void
    {
        $this->owner();

        /*
         | A save that failed and was tried again puts a second copy of every
         | file in Drive, and the two attempts do not number them the same way
         | — so the names disagree while the bytes are identical.
         */
        $this->drive->files['drive-a1'] = ['name' => '2025-11-23 A day 01.jpg', 'folder' => 'f', 'bytes' => 10, 'md5' => 'aaa'];
        $this->drive->files['drive-b1'] = ['name' => '2025-11-23 A day 11.jpg', 'folder' => 'f', 'bytes' => 10, 'md5' => 'aaa'];
        $this->drive->files['drive-a2'] = ['name' => '2025-11-23 A day 02.jpg', 'folder' => 'f', 'bytes' => 10, 'md5' => 'bbb'];

        $this->artisan('memories:import --no-interaction')
            ->expectsOutputToContain('1 identical copy left out')
            ->assertSuccessful();

        $this->assertSame(2, MemoryMedia::query()->count());
        $this->assertSame(2, (int) Memory::firstOrFail()->media_count);
    }

    public function test_two_different_photographs_sharing_a_name_are_both_kept(): void
    {
        $this->owner();

        // Same name, different bytes: two attempts numbering a set differently
        // is not evidence that two photographs are the same photograph.
        $this->drive->files['drive-1'] = ['name' => '2025-11-23 A day 02.jpg', 'folder' => 'f', 'bytes' => 10, 'md5' => 'aaa'];
        $this->drive->files['drive-2'] = ['name' => '2025-11-23 A day 02.jpg', 'folder' => 'f', 'bytes' => 20, 'md5' => 'bbb'];

        $this->artisan('memories:import --no-interaction')->assertSuccessful();

        $this->assertSame(2, MemoryMedia::query()->count());
    }

    public function test_a_file_with_no_checksum_is_kept_rather_than_guessed_at(): void
    {
        $this->owner();

        // Dropping a photograph on a guess is far worse than one copy too many.
        $this->drive->files['drive-1'] = ['name' => '2025-11-23 A day 01.jpg', 'folder' => 'f', 'bytes' => 10, 'md5' => null];
        $this->drive->files['drive-2'] = ['name' => '2025-11-23 A day 02.jpg', 'folder' => 'f', 'bytes' => 10, 'md5' => null];

        $this->artisan('memories:import --no-interaction')->assertSuccessful();

        $this->assertSame(2, MemoryMedia::query()->count());
    }

    public function test_words_can_be_given_back_although_none_are_recoverable(): void
    {
        $this->owner();
        $this->orphan('drive-1', '2025-11-23 A day 01.jpg');

        /*
         | Nothing in Drive carries a description — the file names hold the
         | date and the title and nothing else — so whatever was written when
         | the save failed is gone. It can still be typed back in here rather
         | than only through the interface afterwards.
         */
        $this->artisan('memories:import --no-interaction --description="we ate mangoes on the pier"')
            ->assertSuccessful();

        $this->assertSame('we ate mangoes on the pier', Memory::firstOrFail()->description);
    }

    public function test_a_recovered_memory_has_no_words_unless_they_are_given(): void
    {
        $this->owner();
        $this->orphan('drive-1', '2025-11-23 A day 01.jpg');

        $this->artisan('memories:import --no-interaction')->assertSuccessful();

        // Stated plainly rather than left to be discovered: there was never
        // anywhere for a description to survive.
        $this->assertNull(Memory::firstOrFail()->description);
    }

    public function test_it_says_so_when_there_is_nothing_to_recover(): void
    {
        $this->owner();

        $this->artisan('memories:import --no-interaction')
            ->expectsOutputToContain('Nothing in Drive is unaccounted for')
            ->assertSuccessful();
    }

    public function test_it_refuses_rather_than_guessing_at_an_owner(): void
    {
        $this->aSetOfEight();

        $this->artisan('memories:import --no-interaction')
            ->expectsOutputToContain('no owner yet')
            ->assertFailed();

        $this->assertSame(0, Memory::query()->count());
    }
}
