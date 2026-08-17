<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Memory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A memory as seen while scrolling.
 *
 * The timeline may hold thousands of these, so it carries only what is drawn:
 * no descriptions, no full media list. Opening a memory fetches the rest.
 *
 * @mixin Memory
 */
class TimelineMemoryResource extends JsonResource
{
    /** How many media items the timeline previews per memory. */
    public const PREVIEW_LIMIT = 3;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'memory_date' => $this->memory_date->toDateString(),
            'year' => (int) $this->memory_date->format('Y'),
            'month' => (int) $this->memory_date->format('n'),
            'location' => $this->location,
            'media_count' => $this->media_count,

            /*
             | Resolved to a plain array here rather than handed back as a
             | resource collection. This payload is cached, and a resource
             | object would be stored as a serialised PHP object that comes
             | back from the cache unusable.
             */
            'preview' => $this->relationLoaded('media')
                ? MemoryMediaResource::collection(
                    $this->media->take(self::PREVIEW_LIMIT)->values()
                )->toArray($request)
                : [],
        ];
    }
}
