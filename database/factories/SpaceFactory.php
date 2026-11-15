<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Room;
use App\Models\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare space belongs to a shared room that has no other spaces, so it doesn't
 * break "a private room has exactly one space".
 *
 * @extends Factory<Space>
 */
class SpaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory()->shared(0),
            'landlord_account_id' => fn (array $attributes): mixed => Room::query()
                ->whereKey($attributes['room_id'])->value('landlord_account_id'),
            'label' => 'Bed '.fake()->unique()->numberBetween(1, 999999),
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }
}
