<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Amenity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Amenity>
 */
class AmenityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Amenity '.fake()->unique()->numberBetween(1, 999999);

        return ['name' => $name, 'slug' => Str::slug($name)];
    }
}
