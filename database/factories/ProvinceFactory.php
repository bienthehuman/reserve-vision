<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A made-up province with a unique made-up PSGC code (codes starting 99 aren't used by
 * the PSA). Tests build the few places they need instead of importing the country (4.4).
 *
 * @extends Factory<Province>
 */
class ProvinceFactory extends Factory
{
    /** Codes and slugs from a sequence, not Faker's unique(): the concurrency suite commits its rows and gets a new Faker per test. */
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = ++self::$sequence;
        $name = fake()->city().' Province';

        return [
            'psgc_code' => '99'.str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'name' => $name,
            'slug' => Str::slug($name).'-'.$n,
        ];
    }
}
