<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Memory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A memory as seen when it is opened: everything about it.
 *
 * @mixin Memory
 */
class MemoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'memory_date' => $this->memory_date->toDateString(),
            'year' => (int) $this->memory_date->format('Y'),
            'location' => $this->location,
            'album' => $this->album,
            'media_count' => $this->media_count,

            // A plain array, not a resource collection: this payload is cached,
            // and only plain data survives a round trip through the cache.
            'media' => $this->relationLoaded('media')
                ? MemoryMediaResource::collection($this->media->values())->toArray($request)
                : [],

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
