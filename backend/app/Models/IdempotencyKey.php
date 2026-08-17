<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of a write that has already been accepted, so replaying the same
 * request returns the original answer instead of creating a second memory.
 *
 * @property string $key
 * @property string $endpoint
 * @property string $request_hash
 * @property string $status
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 */
#[Guarded(['id'])]
class IdempotencyKey extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_status' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
