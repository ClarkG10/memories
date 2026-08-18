<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\WarmDerivatives;
use App\Models\Memory;
use App\Models\MemoryMedia;
use App\Models\User;
use App\Services\DriveReconciler;
use App\Services\GoogleDrive\DriveFile;
use App\Services\GoogleDrive\GoogleDriveException;
use App\Services\GoogleDrive\GoogleDriveService;
use App\Services\MemoryCache;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Put photographs that are only in Drive back into the archive.
 *
 * A save that dies after the upload leaves the files in Drive with nothing
 * pointing at them: they are in the account, in the right folder, under the
 * right name, and completely invisible. The obvious answer — delete them and
 * upload again — is the one answer that can lose them, because at that point
 * Drive is the only place they exist.
 *
 * This builds the memory around the files instead. Nothing is uploaded,
 * downloaded or moved; the rows are written to point at what is already there.
 */
class ImportCommand extends Command
{
    protected $signature = 'memories:import
        {--year= : Only consider files filed under this year}
        {--title= : The title for the recovered memory}
        {--date= : The day it happened (YYYY-MM-DD)}
        {--location= : Where it was}
        {--album= : An album name, if it belongs to one}
        {--folder= : Import this Drive folder without asking}
        {--dry-run : Show what would be recovered and change nothing}';

    protected $description = 'Rebuild a memory from files that are in Drive but not in the archive';

    public function handle(
        GoogleDriveService $drive,
        DriveReconciler $reconciler,
        MemoryCache $cache,
    ): int {
        if (! $drive->isConfigured()) {
            $this->components->error('Drive is not connected. Run `php artisan drive:authorize`.');

            return self::FAILURE;
        }

        $owner = User::query()->orderBy('id')->first();

        if ($owner === null) {
            $this->components->error('There is no owner yet. Run `php artisan archive:owner` first.');

            return self::FAILURE;
        }

        $this->components->info('Reading Drive…');

        try {
            $orphans = $reconciler->orphans(
                $this->option('year') !== null ? (int) $this->option('year') : null,
            );
        } catch (GoogleDriveException $e) {
            $this->components->error('Could not read Drive: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($orphans->isEmpty()) {
            $this->components->info('Nothing in Drive is unaccounted for.');

            return self::SUCCESS;
        }

        $groups = $reconciler->groupByFolder($orphans);
        $chosen = $this->chooseGroup($drive, $groups);

        if ($chosen === null) {
            return self::SUCCESS;
        }

        /** @var Collection<int, array{file: DriveFile, parent: string|null}> $found */
        $found = $groups[$chosen];

        /*
         | A save that failed and was tried again left two copies of every
         | file, numbered differently by each attempt. Importing both would
         | rebuild the memory with every photograph in it twice.
         */
        ['kept' => $files, 'duplicates' => $duplicates] = DriveReconciler::dedupe($found);

        $guess = DriveReconciler::readName($files->first()['file']->name);

        /*
         | The names the archive gave these files carry both facts already, so
         | the questions are only asked when they cannot be read back off them
         | — and never at all when nobody is there to answer.
         */
        $title = $this->settle(
            (string) ($this->option('title') ?? ''),
            $guess['title'],
            'What was this memory called?',
        );

        $date = $this->settle(
            (string) ($this->option('date') ?? ''),
            $guess['date'],
            'What day did it happen?',
            fn (string $value): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                ? null
                : 'Use the form 2025-11-23.',
        );

        if ($title === null || $date === null) {
            $this->components->error(
                'Could not work out the title and date from the file names. Pass --title and --date.'
            );

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(sprintf('  "%s" — %s — %d file(s)', $title, $date, $files->count()));

        if ($duplicates->isNotEmpty()) {
            $this->line(sprintf(
                '  %d identical cop%s left out — the same photographs, uploaded twice.',
                $duplicates->count(),
                $duplicates->count() === 1 ? 'y' : 'ies',
            ));
        }

        foreach ($files->take(5) as $entry) {
            $this->line('    '.$entry['file']->name);
        }

        if ($files->count() > 5) {
            $this->line(sprintf('    … and %d more', $files->count() - 5));
        }

        $this->newLine();

        if ($this->option('dry-run')) {
            $this->components->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        if ($this->interactive() && ! confirm('Add this to the archive?', default: true)) {
            return self::SUCCESS;
        }

        $memory = $this->write($owner, $title, $date, $files);

        $cache->flush();

        $this->newLine();
        $this->components->info(sprintf(
            'Recovered "%s" with %d file(s). It is in the archive now.',
            $memory->title,
            $memory->media_count,
        ));
        $this->line('  The photographs will sharpen as they are rendered; a queue worker does that.');

        if ($duplicates->isNotEmpty()) {
            $this->newLine();
            $this->line(sprintf(
                '  %d identical cop%s are still in Drive and belong to nothing.',
                $duplicates->count(),
                $duplicates->count() === 1 ? 'y is' : 'ies are',
            ));
            $this->line('  They are exact copies of photographs now in the archive, so removing');
            $this->line('  them in Drive loses nothing. `memories:reconcile` will keep listing them.');
        }

        return self::SUCCESS;
    }

    /**
     * A value from the flag, else from the file names, else from a person —
     * and if there is no person, honestly nothing.
     */
    private function settle(
        string $given,
        ?string $guess,
        string $label,
        ?callable $validate = null,
    ): ?string {
        if ($given !== '') {
            return $given;
        }

        if (! $this->interactive()) {
            return $guess;
        }

        return text(
            label: $label,
            default: $guess ?? '',
            required: true,
            validate: $validate !== null ? $validate(...) : null,
        );
    }

    /** Whether there is anybody at the other end to answer a question. */
    private function interactive(): bool
    {
        return $this->input->isInteractive();
    }

    /**
     * @param  Collection<string, Collection<int, array{file: DriveFile, parent: string|null}>>  $groups
     */
    private function chooseGroup(GoogleDriveService $drive, Collection $groups): ?string
    {
        if ($this->option('folder') !== null) {
            foreach ($groups as $parent => $group) {
                if ($drive->folderName((string) $parent) === $this->option('folder')) {
                    return (string) $parent;
                }
            }

            $this->components->error('No unaccounted-for files in a folder called "'.$this->option('folder').'".');

            return null;
        }

        if ($groups->count() === 1) {
            return (string) $groups->keys()->first();
        }

        $options = [];

        foreach ($groups as $parent => $group) {
            $guess = DriveReconciler::readName($group->first()['file']->name);

            $options[(string) $parent] = sprintf(
                '%s — %d file(s)%s',
                $drive->folderName((string) $parent) ?? $parent,
                $group->count(),
                $guess['title'] !== null ? ' — looks like "'.$guess['title'].'"' : '',
            );
        }

        return select(label: 'Which set should be recovered?', options: $options);
    }

    /**
     * @param  Collection<int, array{file: DriveFile, parent: string|null}>  $files
     */
    private function write(User $owner, string $title, string $date, Collection $files): Memory
    {
        return DB::transaction(function () use ($owner, $title, $date, $files): Memory {
            /*
             | Through the relation and then force-filled: user_id and
             | media_count are guarded against mass assignment, and quietly
             | dropping either would leave a memory belonging to nobody.
             */
            $memory = $owner->memories()->create([
                'title' => $title,
                'memory_date' => Carbon::parse($date)->toDateString(),
                'location' => $this->option('location'),
                'album' => $this->option('album'),
            ]);

            $memory->forceFill(['media_count' => $files->count()])->save();

            foreach ($files->values() as $index => $entry) {
                $file = $entry['file'];

                $media = $memory->media()->create([
                    'type' => str_starts_with($file->mimeType, 'video/') ? 'video' : 'image',
                    'drive_file_id' => $file->id,
                    'drive_folder_id' => (string) $entry['parent'],
                    'drive_web_view_url' => $file->webViewLink,
                    'drive_thumbnail_url' => $file->thumbnailLink,
                    'file_name' => $file->name,
                    'original_name' => $file->name,
                    'mime_type' => $file->mimeType,
                    'file_size' => $file->size ?? 0,
                    'width' => $file->width,
                    'height' => $file->height,
                    'duration_ms' => $file->durationMs,
                    'checksum' => $file->md5,
                    /*
                     | No blur-up: producing one means downloading the original,
                     | and this command deliberately moves no bytes. The first
                     | render builds it.
                     */
                    'placeholder' => null,
                    'sort_order' => $index,
                    'deletion_state' => MemoryMedia::DELETION_ACTIVE,
                ]);

                DB::afterCommit(fn () => WarmDerivatives::dispatch($media->id));
            }

            return $memory;
        });
    }
}
