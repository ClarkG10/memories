<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MemoryMediaFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One file inside a memory. The bytes live in Google Drive; this row is the
 * catalogue entry that knows where to find them and what state they are in.
 *
 * @property int $id
 * @property string $uuid
 * @property int $memory_id
 * @property string $type
 * @property string $drive_file_id
 * @property string $drive_folder_id
 * @property string|null $drive_thumbnail_url
 * @property string $mime_type
 * @property int $file_size
 * @property int|null $width
 * @property int|null $height
 * @property int|null $duration_ms
 * @property string|null $placeholder
 * @property string $deletion_state
 */
#[Table('memory_media')]
#[Guarded(['id', 'uuid'])]
#[RouteKey('uuid')]
class MemoryMedia extends Model
{
    /** @use HasFactory<MemoryMediaFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    /** Present and serving traffic. */
    public const DELETION_ACTIVE = 'active';

    /** Removal requested; the Drive call is queued or in flight. */
    public const DELETION_DELETING = 'deleting';

    /** Gone from Drive. The row is kept as a tombstone. */
    public const DELETION_DELETED = 'deleted';

    /** Drive refused or was unreachable. Retryable — never silently dropped. */
    public const DELETION_FAILED = 'delete_failed';

    /** How many times a deletion is retried before it needs a human. */
    public const MAX_DELETION_ATTEMPTS = 5;

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
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'sort_order' => 'integer',
            'deletion_attempts' => 'integer',
            'deletion_requested_at' => 'datetime',
        ];
    }

    public function memory(): BelongsTo
    {
        return $this->belongsTo(Memory::class);
    }

    public function isImage(): bool
    {
        return $this->type === self::TYPE_IMAGE;
    }

    public function isVideo(): bool
    {
        return $this->type === self::TYPE_VIDEO;
    }

    /**
     * Whether the bytes are still expected to be in Drive. Once a deletion has
     * been requested the media must stop being served, even if the Drive call
     * has not completed (or has failed) yet.
     */
    public function isServable(): bool
    {
        return $this->deletion_state === self::DELETION_ACTIVE && $this->deleted_at === null;
    }

    /**
     * The intrinsic aspect ratio, used to reserve layout space before the
     * image loads so the timeline never jumps while scrolling.
     */
    public function aspectRatio(): ?float
    {
        if (! $this->width || ! $this->height) {
            return null;
        }

        return round($this->width / $this->height, 4);
    }

    /**
     * @param  Builder<MemoryMedia>  $query
     */
    public function scopeAwaitingDeletion(Builder $query): void
    {
        $query
            ->whereIn('deletion_state', [self::DELETION_DELETING, self::DELETION_FAILED])
            ->where('deletion_attempts', '<', self::MAX_DELETION_ATTEMPTS);
    }
}
