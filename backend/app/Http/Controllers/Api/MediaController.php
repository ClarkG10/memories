<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemoryMedia;
use App\Services\GoogleDrive\GoogleDriveException;
use App\Services\GoogleDrive\GoogleDriveService;
use App\Services\Media\DerivativeService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the media.
 *
 * Drive files are private and the browser has no credentials for them, so
 * every byte a visitor sees passes through here. That is also what keeps the
 * Drive account invisible: nothing in the front end ever learns a file id.
 */
class MediaController extends Controller
{
    public function __construct(
        private readonly DerivativeService $derivatives,
        private readonly GoogleDriveService $drive,
    ) {}

    /**
     * A photo at roughly the requested width.
     */
    public function image(Request $request, MemoryMedia $media): BinaryFileResponse
    {
        $this->ensureServable($media);

        abort_unless($media->isImage(), 404);

        $width = (int) $request->integer('w', 1600);
        $path = $this->derivatives->imagePath($media, $width);

        abort_if($path === null, 404, 'That photo is not available right now.');

        return $this->fileResponse($path, 'image/jpeg');
    }

    /**
     * The still frame shown before a video plays.
     */
    public function poster(MemoryMedia $media): BinaryFileResponse
    {
        $this->ensureServable($media);

        $path = $this->derivatives->posterPath($media);

        // Drive generates video thumbnails after the upload finishes, so this
        // can legitimately be missing for a minute or two. The timeline has a
        // designed fallback rather than a broken image.
        abort_if($path === null, 404, 'No preview yet.');

        return $this->fileResponse($path, 'image/jpeg');
    }

    /**
     * The video itself, proxied from Drive with range support so the player
     * can seek and the browser can start playing before the file is finished.
     */
    public function stream(Request $request, MemoryMedia $media): StreamedResponse
    {
        $this->ensureServable($media);

        abort_unless($media->isVideo(), 404);

        $range = $request->header('Range');

        try {
            $upstream = $this->drive->download($media->drive_file_id, is_string($range) ? $range : null);
        } catch (GoogleDriveException) {
            abort(502, "We couldn't reach this video right now.");
        }

        $status = $upstream->getStatusCode();

        if ($status === Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
            abort(416);
        }

        abort_if($status >= 400, 502, "We couldn't reach this video right now.");

        $headers = array_filter([
            'Content-Type' => $media->mime_type,
            'Accept-Ranges' => 'bytes',
            'Content-Length' => $upstream->getHeaderLine('Content-Length') ?: null,
            'Content-Range' => $upstream->getHeaderLine('Content-Range') ?: null,
            'Cache-Control' => $this->cacheControl(),
            'Content-Disposition' => 'inline; filename="'.addslashes($media->file_name).'"',
        ]);

        return response()->stream(function () use ($upstream): void {
            $body = $upstream->getBody();

            while (! $body->eof()) {
                echo $body->read(262144);

                // Push each block out rather than accumulating the whole
                // video in PHP's output buffer.
                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();

                // Someone scrubbed the timeline or closed the tab: stop
                // pulling bytes out of Drive for a response nobody wants.
                if (connection_aborted() !== 0) {
                    break;
                }
            }

            $body->close();
        }, $status, $headers);
    }

    /**
     * Media that is being deleted stops being served the moment removal is
     * requested, whether or not Drive has caught up.
     */
    private function ensureServable(MemoryMedia $media): void
    {
        abort_unless($media->isServable(), 404, 'That memory is no longer here.');
    }

    private function fileResponse(string $path, string $contentType): BinaryFileResponse
    {
        $response = response()->file($path, ['Content-Type' => $contentType]);

        /*
         | Set through Symfony's cache API rather than as a raw header string:
         | the header bag recomputes Cache-Control from its own directive list,
         | and a hand-written "private" gets overwritten with "public" on the
         | way out.
         */
        if (config('memories.public') === true) {
            $response->setPublic();
            $response->setMaxAge(31_536_000);
            $response->headers->addCacheControlDirective('immutable');
        } else {
            $response->setPrivate();
            $response->setMaxAge(86_400);
        }

        // Lets the browser revalidate cheaply once max-age lapses.
        $response->setAutoEtag();
        $response->setAutoLastModified();

        return $response;
    }

    /**
     * A given URL always yields the same bytes — the width is part of the path
     * and media is never rewritten in place — so it can be cached hard. A
     * private archive keeps that out of shared caches.
     */
    private function cacheControl(): string
    {
        return config('memories.public') === true
            ? 'public, max-age=31536000, immutable'
            : 'private, max-age=86400';
    }
}
