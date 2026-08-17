<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MemoryMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * @mixin MemoryMedia
 */
class MemoryMediaResource extends JsonResource
{
    /**
     * Media never exposes a Drive link or a database id — only this archive's
     * own URLs, which are the sole way to reach the bytes.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'width' => $this->width,
            'height' => $this->height,
            'aspect_ratio' => $this->aspectRatio(),
            'duration_ms' => $this->duration_ms,

            // A ~1 KB inline image so something is on screen before any
            // network request for the real photo has finished.
            'placeholder' => $this->placeholder,

            'urls' => $this->urls(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function urls(): array
    {
        if ($this->isVideo()) {
            return [
                'poster' => $this->mediaUrl('media.poster', ['media' => $this->uuid]),
                'stream' => $this->mediaUrl('media.stream', ['media' => $this->uuid]),
            ];
        }

        return [
            'thumb' => $this->mediaUrl('media.image', ['media' => $this->uuid, 'w' => 640]),
            'display' => $this->mediaUrl('media.image', ['media' => $this->uuid, 'w' => 1600]),
            'full' => $this->mediaUrl('media.image', ['media' => $this->uuid, 'w' => 2400]),
        ];
    }

    /**
     * A public archive gets a plain URL, which never changes and so can be
     * cached by the browser for a year.
     *
     * A private one gets a signed URL, because an <img> tag cannot present a
     * bearer token. The expiry is rounded to the start of a day so the URL is
     * stable for everyone reading the archive that day — a signature minted
     * per request would change the URL constantly and defeat browser caching
     * entirely.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function mediaUrl(string $route, array $parameters): string
    {
        if (config('memories.public') === true) {
            return route($route, $parameters);
        }

        return URL::temporarySignedRoute(
            $route,
            now()->addDays(7)->startOfDay(),
            $parameters,
        );
    }
}
