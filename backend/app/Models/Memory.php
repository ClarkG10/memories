<?php

declare(strict_types=1);

namespace App\Models;

use App\Policies\MemoryPolicy;
use Database\Factories\MemoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single remembered moment, which may hold any number of photos and videos.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $title
 * @property string|null $description
 * @property Carbon $memory_date
 * @property string|null $location
 * @property int $media_count
 */
#[Fillable(['title', 'description', 'memory_date', 'location'])]
#[RouteKey('uuid')]
#[UsePolicy(MemoryPolicy::class)]
class Memory extends Model
{
    /** @use HasFactory<MemoryFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Only the public identifier is a UUID; the primary key stays an integer.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'memory_date' => 'date',
            'media_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<MemoryMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(MemoryMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Newest first, with the id breaking ties so that memories sharing a date
     * keep a stable order across paginated requests.
     *
     * @param  Builder<Memory>  $query
     */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('memory_date')->orderByDesc('id');
    }

    /**
     * @param  Builder<Memory>  $query
     */
    public function scopeForYear(Builder $query, int $year): void
    {
        $query->whereYear('memory_date', $year);
    }
}
