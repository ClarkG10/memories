<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Memory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Memory>
 */
class MemoryFactory extends Factory
{
    protected $model = Memory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => rtrim($this->faker->sentence(4), '.'),
            'description' => $this->faker->optional()->paragraph(),
            'memory_date' => $this->faker->dateTimeBetween('-4 years', 'now')->format('Y-m-d'),
            'location' => $this->faker->optional()->city(),
            'media_count' => 0,
        ];
    }

    public function on(string $date): self
    {
        return $this->state(fn (): array => ['memory_date' => $date]);
    }
}
