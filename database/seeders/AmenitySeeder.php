<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Amenity;
use Illuminate\Database\Seeder;

/** Reference data. Idempotent: a slug that exists is left alone. */
final class AmenitySeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, string> $catalogue */
        $catalogue = require database_path('data/amenities.php');

        foreach ($catalogue as $slug => $name) {
            Amenity::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
