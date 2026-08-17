<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file being carried from the browser to Drive, one chunk at a time.
 *
 * @property int $id
 * @property string $uuid
 * @property string $original_name
 * @property string|null $mime_type
 * @property string|null $type
 * @property int $size
 * @property int $chunk_size
 * @property int $total_chunks
 * @property int $received_chunks
 * @property array<int, int>|null $chunk_map
 * @property string $path
 * @property string $status
 */
#[Guarded(['id', 'uuid'])]
#[RouteKey('uuid')]
class UploadSession extends Model
{
    use HasUuids;

    /** Chunks are still arriving. */
    public const STATUS_PENDING = 'pending';

    /** Every chunk arrived and the file passed validation. */
    public const STATUS_READY = 'ready';

    /** Handed to a memory and uploaded to Drive; the temp file is gone. */
    public const STATUS_CONSUMED = 'consumed';

    /** Rejected — wrong type, wrong size, or a corrupt assembly. */
    public const STATUS_FAILED = 'failed';

    /** Abandoned by the user and swept up by `uploads:prune`. */
    public const STATUS_EXPIRED = 'expired';

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'chunk_map' => 'array',
            'size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'received_chunks' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * Indices the browser still needs to send. Sent back on every chunk
     * response so an interrupted upload can resume instead of restarting.
     *
     * @return array<int, int>
     */
    public function missingChunks(): array
    {
        $received = $this->chunk_map ?? [];

        return array_values(array_diff(range(0, $this->total_chunks - 1), $received));
    }

    /**
     * @param  Builder<UploadSession>  $query
     */
    public function scopeReclaimable(Builder $query): void
    {
        /*
         | "ready" belongs here too. A session reaches that state holding the
         | complete file, and it is only cleared once a memory claims it — so a
         | save abandoned after the upload finished, or a duplicate dropped
         | during de-duplication, would otherwise keep a full-size video on
         | disk for good. Past its expiry, nothing is coming to claim it.
         */
        $query
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_READY, self::STATUS_FAILED])
            ->where('expires_at', '<', now());
    }
}
