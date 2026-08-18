<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MemoryMedia;
use App\Services\Media\DerivativeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Render a photograph's sizes before anyone asks for them.
 *
 * Every rendition is generated on the first request for it, which means the
 * first person to look at a memory pays for downloading the original from
 * Drive and resizing it — several seconds, on the one view where the archive
 * should feel immediate. Since the first person to look at a new memory is
 * almost always whoever just saved it, that cost lands squarely on them.
 *
 * Doing it here moves that work off the request and onto the queue, where
 * nobody is watching. Failing is survivable: the derivative is generated on
 * demand exactly as before, only slowly.
 */
class WarmDerivatives implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $mediaId) {}

    /** Only worth doing once per file; a duplicate is pure waste. */
    public function uniqueId(): string
    {
        return (string) $this->mediaId;
    }

    public function handle(DerivativeService $derivatives): void
    {
        $media = MemoryMedia::query()->find($this->mediaId);

        if ($media === null || $media->deletion_state !== MemoryMedia::DELETION_ACTIVE) {
            return;
        }

        if ($media->isVideo()) {
            // Drive needs a minute to produce a still of its own. If the
            // browser sent one at upload it is already stored, and this is a
            // no-op; if not, this is the attempt that fetches Drive's.
            $derivatives->posterPath($media);

            return;
        }

        /** @var array<int, int> $widths */
        $widths = (array) config('memories.derivatives.sizes', []);

        foreach ($widths as $width) {
            try {
                $derivatives->imagePath($media, (int) $width);
            } catch (Throwable $e) {
                /*
                 | One size failing should not abandon the others: a photograph
                 | whose largest rendition is too big to build here still has a
                 | perfectly good thumbnail, and the timeline only needs that.
                 */
                Log::warning('Could not warm a derivative.', [
                    'media_uuid' => $media->uuid,
                    'width' => $width,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Warming derivatives failed; they will be built on demand.', [
            'media_id' => $this->mediaId,
            'error' => $e->getMessage(),
        ]);
    }
}
