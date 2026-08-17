<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for reading the archive.
 *
 * A private archive answers nothing at all to a stranger — not an empty
 * timeline, which would still confirm the archive exists and is empty.
 */
class EnsureArchiveIsViewable
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('memories.public') === true || $request->user() !== null) {
            return $next($request);
        }

        abort(403, 'This archive is private.');
    }
}
