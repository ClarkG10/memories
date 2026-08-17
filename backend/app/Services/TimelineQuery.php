<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Resources\MemoryResource;
use App\Http\Resources\TimelineMemoryResource;
use App\Models\Memory;
use App\Models\MemoryMedia;
use Illuminate\Http\Request;

/**
 * Reads for the timeline, and the cache in front of them.
 *
 * Cached values are the already-rendered arrays rather than models, so a hit
 * costs one Redis round trip and no hydration at all.
 */
class TimelineQuery
{
    public function __construct(private readonly MemoryCache $cache) {}

    /**
     * One page of the timeline, newest first.
     *
     * @return array{data: array<int, mixed>, meta: array{next_cursor: string|null, has_more: bool}}
     */
    public function page(Request $request, ?int $year, ?string $cursor, int $perPage): array
    {
        $signature = sha1(json_encode([$year, $cursor, $perPage]) ?: '');

        return $this->cache->timeline($signature, function () use ($request, $year, $cursor, $perPage): array {
            $query = Memory::query()
                ->when($year !== null, fn ($q) => $q->forYear($year))
                ->newestFirst()
                /*
                 | Eager load only the first few media per memory. Without the
                 | limit a year of large memories would drag every file's row
                 | into a list that shows three of them.
                 */
                ->with(['media' => fn ($q) => $q
                    ->where('deletion_state', MemoryMedia::DELETION_ACTIVE)
                    ->limit(TimelineMemoryResource::PREVIEW_LIMIT),
                ]);

            $paginator = $query->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

            return [
                'data' => TimelineMemoryResource::collection($paginator->items())->toArray($request),
                'meta' => [
                    'next_cursor' => $paginator->nextCursor()?->encode(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ];
        });
    }

    /**
     * Every year that holds something, with how much — the spine of the
     * timeline's navigation.
     *
     * @return array<int, array{year: int, count: int}>
     */
    public function years(): array
    {
        return $this->cache->years(function (): array {
            return Memory::query()
                ->selectRaw('YEAR(memory_date) as year, COUNT(*) as total')
                ->groupBy('year')
                ->orderByDesc('year')
                ->get()
                ->map(fn ($row): array => [
                    'year' => (int) $row->year,
                    'count' => (int) $row->total,
                ])
                ->all();
        });
    }

    /**
     * One memory in full, with all of its media.
     *
     * @return array<string, mixed>|null
     */
    public function find(Request $request, string $uuid): ?array
    {
        return $this->cache->memory($uuid, function () use ($request, $uuid): ?array {
            $memory = Memory::query()
                ->where('uuid', $uuid)
                ->with(['media' => fn ($q) => $q->where('deletion_state', MemoryMedia::DELETION_ACTIVE)])
                ->first();

            if ($memory === null) {
                return null;
            }

            return (new MemoryResource($memory))->toArray($request);
        });
    }
}
