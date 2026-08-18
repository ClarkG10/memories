<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a name, so a failure someone saw can be found in the log.
 *
 * Without this, "it broke" and a log full of stack traces are two facts with
 * nothing joining them. The id goes into the log context for every line the
 * request writes, comes back on the response header, and is shown to the
 * person when something actually went wrong — so "it broke, reference 9f2c…"
 * is a question that can be answered.
 *
 * It is deliberately not derived from anything about the person: it identifies
 * one request, and is meaningless an hour later.
 */
class AttachRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::of(Str::uuid()->toString())->replace('-', '')->substr(0, 12);

        $request->attributes->set('request_id', $id);

        /*
         | Applies to every log line written from here on, including ones
         | written deep inside a service that has no idea a request exists.
         */
        Log::withContext([
            'request_id' => $id,
            'route' => $request->method().' '.$request->path(),
            'ip' => $request->ip(),
        ]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER, $id);

        /*
         | Only on the failures worth looking up. A validation message is for
         | the person to act on and carries nothing a log would add; a 500 is
         | the opposite — the useful half is in the log, and this is the thread
         | back to it.
         */
        if ($response->getStatusCode() >= 500 && $response instanceof JsonResponse) {
            $payload = $response->getData(true);

            if (is_array($payload) && ! array_key_exists('reference', $payload)) {
                $payload['reference'] = $id;
                $response->setData($payload);
            }
        }

        return $response;
    }
}
