<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LandlordAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LandlordAccount>
 */
class LandlordAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->lastName().' Rentals';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'contact_phone' => '09'.fake()->numerify('#########'),
            'is_verified' => false,
            'verified_at' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(['is_verified' => true, 'verified_at' => now()]);
    }
}
