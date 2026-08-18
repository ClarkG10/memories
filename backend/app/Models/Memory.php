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
 * @property string|null $album
 * @property int $media_count
 */
#[Fillable(['title', 'description', 'memory_date', 'location', 'album'])]
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

    /**
     * Memories that answer to a phrase.
     *
     * Every word has to appear somewhere — in the title, the description, the
     * place or the album — but not all in the same one. That is what people
     * expect of a search box and what almost nothing does by default: an OR
     * across words returns the whole archive for any common word, and an AND
     * within a single field cannot find "wedding butuan" because the two
     * halves live in different columns.
     *
     * A word that is a year is also matched against the date, so typing 2025
     * finds that year even when nothing says so in words.
     *
     * @param  Builder<Memory>  $query
     */
    public function scopeMatching(Builder $query, string $phrase): void
    {
        $words = preg_split('/\s+/u', trim($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (array_slice($words, 0, 8) as $word) {
            $query->where(function (Builder $group) use ($word): void {
                /*
                 | Escaped by hand: a title containing % or _ is a search for
                 | that character, not a wildcard someone did not ask for.
                 */
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word).'%';

                $group->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('location', 'like', $like)
                    ->orWhere('album', 'like', $like);

                if (preg_match('/^\d{4}$/', $word) === 1) {
                    $group->orWhereYear('memory_date', (int) $word);
                }
            });
        }
    }

    /**
     * Every album that has been used, most recent first.
     *
     * There is no albums table: an album is just a name written on a memory,
     * so the list of them is whatever names are in use. Nothing to create,
     * nothing to tidy up when the last memory using one is removed.
     *
     * @return array<int, string>
     */
    public static function albums(): array
    {
        return self::query()
            ->whereNotNull('album')
            ->selectRaw('album, MAX(memory_date) as latest')
            ->groupBy('album')
            ->orderByDesc('latest')
            ->pluck('album')
            ->all();
    }
}
