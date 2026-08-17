<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Memory;
use App\Models\MemoryMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MemoryMedia>
 */
class MemoryMediaFactory extends Factory
{
    protected $model = MemoryMedia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'memory_id' => Memory::factory(),
            'type' => MemoryMedia::TYPE_IMAGE,
            'drive_file_id' => 'drive-'.Str::random(28),
            'drive_folder_id' => 'folder-'.Str::random(20),
            'drive_web_view_url' => null,
            'drive_thumbnail_url' => null,
            'file_name' => Str::random(12).'.jpg',
            'original_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => $this->faker->numberBetween(200_000, 6_000_000),
            'width' => 2400,
            'height' => 1600,
            'duration_ms' => null,
            'checksum' => hash('sha256', Str::random(32)),
            'placeholder' => null,
            'sort_order' => 0,
            'deletion_state' => MemoryMedia::DELETION_ACTIVE,
        ];
    }

    public function video(): self
    {
        return $this->state(fn (): array => [
            'type' => MemoryMedia::TYPE_VIDEO,
            'mime_type' => 'video/mp4',
            'file_name' => Str::random(12).'.mp4',
            'original_name' => 'clip.mp4',
            'file_size' => $this->faker->numberBetween(5_000_000, 400_000_000),
            'width' => 1920,
            'height' => 1080,
            'duration_ms' => $this->faker->numberBetween(3_000, 180_000),
        ]);
    }

    public function deleting(): self
    {
        return $this->state(fn (): array => [
            'deletion_state' => MemoryMedia::DELETION_DELETING,
            'deletion_requested_at' => now(),
        ]);
    }

    public function deleteFailed(): self
    {
        return $this->state(fn (): array => [
            'deletion_state' => MemoryMedia::DELETION_FAILED,
            'deletion_error' => 'Drive returned 503.',
            'deletion_attempts' => 1,
            'deletion_requested_at' => now(),
        ]);
    }
}
