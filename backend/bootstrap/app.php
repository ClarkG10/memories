<?php

declare(strict_types=1);

use App\Exceptions\MemoryUploadException;
use App\Http\Middleware\EnsureArchiveIsViewable;
use App\Http\Middleware\EnsureMediaIsViewable;
use App\Services\GoogleDrive\GoogleDriveException;
use App\Services\Media\UnsupportedMediaException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'archive.viewable' => EnsureArchiveIsViewable::class,
            'media.viewable' => EnsureMediaIsViewable::class,
        ]);

        /*
         | The app is only ever reached through the web server in front of it,
         | which terminates TLS and forwards the original client details in
         | X-Forwarded-* headers. Without trusting those, rate limits would see
         | one address for every visitor and generated links would be http.
         */
        $middleware->trustProxies(at: '*');

        // The React app is a separate origin, so preflight and CORS headers
        // apply to every API route.
        $middleware->api(prepend: [
            HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         | Errors reach the person as sentences, not status codes. The detail
         | that helps diagnose the problem goes to the log; what comes back
         | says what happened and whether trying again is worth it.
         */

        $exceptions->render(function (MemoryUploadException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            Log::error('Memory upload failed.', ['error' => $e->getMessage(), 'cause' => $e->getPrevious()?->getMessage()]);

            return response()->json([
                'message' => $e->getMessage(),
                'retryable' => $e->retryable,
            ], 422);
        });

        $exceptions->render(function (UnsupportedMediaException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'retryable' => false,
            ], 422);
        });

        $exceptions->render(function (GoogleDriveException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            Log::error('Google Drive call failed.', [
                'error' => $e->getMessage(),
                'status' => $e->status,
                'reason' => $e->reason,
            ]);

            return response()->json([
                'message' => $e->isQuotaExhausted()
                    ? "There's no space left in the connected Google Drive."
                    : "We couldn't reach the place your memories are stored. Please try again.",
                'retryable' => $e->isRetryable(),
            ], 503);
        });
    })->create();
