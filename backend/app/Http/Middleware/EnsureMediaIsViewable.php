<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the bytes themselves.
 *
 * Reading the timeline is a fetch() and can carry a bearer token. A photograph
 * is an <img src>, and a video is a <video src> — the browser issues those
 * itself and there is no way to attach a header to them. Gating media on the
 * token alone therefore means a private archive displays nothing at all.
 *
 * So a private archive hands out signed URLs instead: the proof of access
 * travels in the query string, where an img tag can carry it. The signature
 * covers the media id and the requested width, so it cannot be edited into a
 * URL for a different file.
 */
class EnsureMediaIsViewable
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('memories.public') === true || $request->user() !== null) {
            return $next($request);
        }

        if ($request->hasValidSignature()) {
            return $next($request);
        }

        abort(403, 'This archive is private.');
    }
}
