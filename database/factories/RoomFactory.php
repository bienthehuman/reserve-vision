<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoomType;
use App\Models\Property;
use App\Models\Room;
use App\Models\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A private room gets its single "Whole room" space; `shared()` creates one
 * space per bed. `landlord_account_id` always comes from the property.
 *
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'landlord_account_id' => fn (array $attributes): mixed => Property::query()
                ->whereKey($attributes['property_id'])->value('landlord_account_id'),
            'name' => 'Room '.fake()->unique()->numberBetween(1, 999999),
            'room_type' => RoomType::Private,
            'monthly_rate' => fake()->numberBetween(20, 250) * 10000, // ₱2,000 to ₱25,000, in centavos
            'deposit_months' => 1,
            'advance_months' => 1,
            'reservation_fee' => 150000, // ₱1,500.00
            'archived_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Room $room): void {
            if ($room->room_type === RoomType::Private && ! $room->spaces()->exists()) {
                Space::factory()->for($room)->create(['label' => 'Whole room']);
            }
        });
    }

    /**
     * A shared room with $beds spaces, "Bed A", "Bed B", …
     */
    public function shared(int $beds = 4): static
    {
        return $this->state(['room_type' => RoomType::Shared])
            ->afterCreating(function (Room $room) use ($beds): void {
                for ($i = 0; $i < $beds; $i++) {
                    Space::factory()->for($room)->create(['label' => 'Bed '.chr(ord('A') + $i)]);
                }
            });
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }
}
