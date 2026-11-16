<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Barangay;
use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Barangay>
 */
class BarangayFactory extends Factory
{
    /** Codes and slugs from a sequence, not Faker's unique(): the concurrency suite commits its rows and gets a new Faker per test. */
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = ++self::$sequence;

        return [
            'city_id' => City::factory(),
            'psgc_code' => '99'.str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'name' => fake()->streetName(),
            'district' => null,
        ];
    }
}
