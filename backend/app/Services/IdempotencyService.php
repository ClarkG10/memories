<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IdempotencyKey;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Makes a write happen at most once, however many times the request arrives.
 *
 * A double-tapped button, an impatient refresh, or a proxy replaying a request
 * after a timeout all look identical to the server. The client stamps one key
 * per intent; the unique index on that key is what actually enforces the rule,
 * so no amount of client-side cleverness is being relied upon.
 */
class IdempotencyService
{
    /**
     * How long a claim may sit unfinished before it is treated as abandoned.
     *
     * Comfortably longer than any single request, including one uploading a
     * large video. Anything still in progress after this did not finish — the
     * worker was killed, or PHP hit its time limit — and its exception handler
     * never ran to release the key.
     */
    private const STALE_CLAIM_MINUTES = 15;

    /**
     * Run the callback under a key, or return the response it produced the
     * first time round.
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(): JsonResponse  $callback
     */
    public function run(User $user, string $key, string $endpoint, array $payload, Closure $callback): JsonResponse
    {
        $hash = $this->hash($payload);
        $record = $this->claim($user, $key, $endpoint, $hash)
            ?? $this->reclaimIfAbandoned($user, $key, $endpoint, $hash);

        if ($record === null) {
            return $this->replay($user, $key, $hash);
        }

        try {
            $response = $callback();
        } catch (Throwable $e) {
            /*
             | A failed attempt must not poison the key: the person is going to
             | press "Try again" with the same one, and that has to be allowed
             | to work.
             */
            $record->delete();

            throw $e;
        }

        $record->forceFill([
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'response_status' => $response->getStatusCode(),
            'response_body' => json_decode($response->getContent() ?: '[]', true),
        ])->save();

        return $response;
    }

    /**
     * Take ownership of the key, or return null if someone already has it.
     */
    private function claim(User $user, string $key, string $endpoint, string $hash): ?IdempotencyKey
    {
        try {
            return IdempotencyKey::create([
                'user_id' => $user->id,
                'key' => $key,
                'endpoint' => $endpoint,
                'request_hash' => $hash,
                'status' => IdempotencyKey::STATUS_IN_PROGRESS,
                'expires_at' => now()->addDay(),
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Release a claim whose request never finished, and take it over.
     *
     * Without this, a process killed mid-save leaves the key locked forever.
     * The client keeps retrying with the same key — that is the whole point of
     * the key — so it would get 409 every time and the memory could never be
     * saved. Returns null when the existing claim is legitimately live or
     * already complete.
     */
    private function reclaimIfAbandoned(User $user, string $key, string $endpoint, string $hash): ?IdempotencyKey
    {
        $existing = IdempotencyKey::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->first();

        if ($existing === null || $existing->isCompleted()) {
            return null;
        }

        if ($existing->created_at === null
            || $existing->created_at->gt(now()->subMinutes(self::STALE_CLAIM_MINUTES))) {
            return null;
        }

        Log::warning('Reclaiming an abandoned idempotency key.', [
            'key' => $key,
            'claimed_at' => $existing->created_at->toIso8601String(),
        ]);

        $existing->delete();

        return $this->claim($user, $key, $endpoint, $hash);
    }

    private function replay(User $user, string $key, string $hash): JsonResponse
    {
        $existing = IdempotencyKey::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->first();

        if ($existing === null) {
            // Vanished between the insert failing and this read — the safest
            // reading is that another request is mid-flight.
            throw new ConflictHttpException('That memory is already being saved.');
        }

        if ($existing->request_hash !== $hash) {
            throw new ConflictHttpException(
                'That request key has already been used for a different memory.'
            );
        }

        if (! $existing->isCompleted()) {
            throw new ConflictHttpException('That memory is already being saved. Give it a moment.');
        }

        Log::info('Replayed an idempotent request.', ['key' => $key]);

        return new JsonResponse(
            $existing->response_body ?? [],
            $existing->response_status ?? 200,
        );
    }

    /**
     * Drop keys old enough that no client would still be retrying them.
     */
    public function prune(): int
    {
        return IdempotencyKey::query()
            ->where('expires_at', '<', now())
            ->orWhere(fn ($query) => $query
                ->where('status', IdempotencyKey::STATUS_IN_PROGRESS)
                ->where('created_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES)))
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload));
    }

    private function isDuplicate(QueryException $e): bool
    {
        // 23000/23505 — integrity constraint violation across MySQL, MariaDB
        // and Postgres.
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
