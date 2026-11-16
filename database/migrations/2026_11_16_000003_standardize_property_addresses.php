<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Philippine address in four parts: street address (optional), barangay, city, province.
 * `lat` and `lng` go: nobody types coordinates, and no page used them.
 *
 * Rows from before this migration: the province is filled in from the city, and a missing
 * barangay becomes "Not set" for the owner to correct. Demo data is reseeded instead
 * (`docker compose down -v && bin/setup`), which also shortens its street addresses.
 */
return new class extends Migration
{
    /** City slug → province, as `database/data/places.php` had them on this date (migrations are frozen history). */
    private const array PROVINCES = [
        'manila' => 'Metro Manila',
        'quezon-city' => 'Metro Manila',
        'makati' => 'Metro Manila',
        'cebu-city' => 'Cebu',
        'davao-city' => 'Davao del Sur',
        'baguio' => 'Benguet',
        'iloilo-city' => 'Iloilo',
    ];

    public function up(): void
    {
        // The CHECKs first: neither server drops a column a CHECK still names.
        DB::statement('ALTER TABLE `properties` DROP CONSTRAINT `properties_lat_in_range`');
        DB::statement('ALTER TABLE `properties` DROP CONSTRAINT `properties_lng_in_range`');

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng']);
            $table->string('province')->default('')->after('city_slug');
        });

        foreach (self::PROVINCES as $citySlug => $province) {
            DB::table('properties')->where('city_slug', $citySlug)->update(['province' => $province]);
        }
        DB::table('properties')->whereNull('barangay')->update(['barangay' => 'Not set']);

        Schema::table('properties', function (Blueprint $table) {
            $table->string('province')->default(null)->change();
            $table->string('barangay')->nullable(false)->change();
            $table->string('address')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('properties')->whereNull('address')->update(['address' => '']);

        Schema::table('properties', function (Blueprint $table) {
            $table->string('address')->nullable(false)->change();
            $table->string('barangay')->nullable()->change();
            $table->dropColumn('province');
            $table->decimal('lat', 9, 6)->nullable()->after('address');
            $table->decimal('lng', 9, 6)->nullable()->after('lat');
        });

        DB::statement('ALTER TABLE `properties` ADD CONSTRAINT `properties_lat_in_range` CHECK (`lat` IS NULL OR `lat` BETWEEN -90 AND 90)');
        DB::statement('ALTER TABLE `properties` ADD CONSTRAINT `properties_lng_in_range` CHECK (`lng` IS NULL OR `lng` BETWEEN -180 AND 180)');
    }
};
