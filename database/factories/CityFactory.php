<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CityKind;
use App\Models\City;
use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    /** Codes and slugs from a sequence, not Faker's unique(): the concurrency suite commits its rows and gets a new Faker per test. */
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = ++self::$sequence;
        $name = fake()->city();

        return [
            'province_id' => Province::factory(),
            'psgc_code' => '99'.str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'name' => $name,
            'slug' => Str::slug($name).'-'.$n,
            'kind' => CityKind::City,
        ];
    }
}
