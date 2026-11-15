<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Runs as the `app` user, so seeders may
     * only insert, update and delete. Must stay idempotent: `bin/setup` runs it
     * on every invocation. The amenity catalogue is reference data (always);
     * the demo data is skipped in production unless APP_DEMO=true.
     */
    public function run(): void
    {
        $this->call(AmenitySeeder::class);

        if (app()->environment('production') && ! config('app.demo')) {
            $this->command?->info('Skipping demo data in production (set APP_DEMO=true to seed it).');

            return;
        }

        $this->call(DemoSeeder::class);
    }
}
