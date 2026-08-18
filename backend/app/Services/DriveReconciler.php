<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MemoryMedia;
use App\Services\GoogleDrive\DriveFile;
use App\Services\GoogleDrive\GoogleDriveException;
use App\Services\GoogleDrive\GoogleDriveService;
use Illuminate\Support\Collection;

/**
 * Where Drive and the archive disagree.
 *
 * Shared by the command that reports the disagreement and the one that repairs
 * it, so the two can never differ about what counts as an orphan — which would
 * be the worst kind of bug here: a repair tool acting on a definition the
 * report does not use.
 */
final class DriveReconciler
{
    public function __construct(private readonly GoogleDriveService $drive) {}

    /**
     * Files in Drive that no memory claims, newest folder first.
     *
     * A soft-deleted memory still owns its files until the queue collects
     * them, so those are deliberately not orphans — the ordinary gap between
     * asking for a removal and it happening is not a fault.
     *
     * @return Collection<int, array{file: DriveFile, parent: string|null}>
     *
     * @throws GoogleDriveException
     */
    public function orphans(?int $year = null): Collection
    {
        $claimed = MemoryMedia::withTrashed()->pluck('drive_file_id')->flip();

        return collect($this->drive->listOwnFiles())
            ->reject(fn (array $entry): bool => $claimed->has($entry['file']->id))
            ->filter(function (array $entry) use ($year): bool {
                // The name carries the date it was filed under.
                return $year === null || str_starts_with($entry['file']->name, (string) $year);
            })
            ->values();
    }

    /**
     * @param  Collection<int, array{file: DriveFile, parent: string|null}>  $orphans
     * @return Collection<string, Collection<int, array{file: DriveFile, parent: string|null}>>
     */
    public function groupByFolder(Collection $orphans): Collection
    {
        return $orphans
            ->groupBy(fn (array $entry): string => $entry['parent'] ?? 'unknown')
            ->map(fn (Collection $group): Collection => $group->sortBy(
                // The trailing number is the order they were saved in.
                fn (array $entry): string => $entry['file']->name,
                SORT_NATURAL,
            )->values());
    }

    /**
     * Photographs the archive holds that are no longer in Drive. These are the
     * ones that render as a broken image with nothing to explain them.
     *
     * @return Collection<int, MemoryMedia>
     *
     * @throws GoogleDriveException
     */
    public function missing(): Collection
    {
        $present = collect($this->drive->listOwnFiles())
            ->pluck('file.id')
            ->flip();

        return MemoryMedia::query()
            ->where('deletion_state', MemoryMedia::DELETION_ACTIVE)
            ->get()
            ->reject(fn (MemoryMedia $media): bool => $present->has($media->drive_file_id))
            ->values();
    }

    /**
     * What the archive named a file when it saved it:
     *
     *   2025-11-23 Read this to remember 07.jpg
     *
     * The date and the title were the memory's own, so an orphaned set can
     * suggest what it used to be rather than asking someone to remember.
     *
     * @return array{date: string|null, title: string|null}
     */
    public static function readName(string $name): array
    {
        $withoutExtension = preg_replace('/\.[A-Za-z0-9]+$/', '', $name) ?? $name;

        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(.*?)\s*\d*$/', $withoutExtension, $matches) !== 1) {
            return ['date' => null, 'title' => null];
        }

        $title = trim($matches[2]);

        return [
            'date' => $matches[1],
            'title' => $title === '' ? null : $title,
        ];
    }
}
