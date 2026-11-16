<?php

declare(strict_types=1);

use App\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every province, city and municipality, and barangay of the PSA's PSGC list (slice 4.4),
 * filled by `php artisan places:import` from `database/data/psgc/`. Reference tables: no
 * landlord account, no isolation scope. A property links to its city and barangay; the text
 * columns (`barangay`, `city`, `city_slug`, `province`) stay as copies the actions write.
 * The links are nullable for rows from before this migration, which the import links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->char('psgc_code', 10)->unique();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained('provinces');
            $table->char('psgc_code', 10)->unique();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('kind', 20);

            $table->index(['province_id', 'name']);
        });
        Constraints::in('cities', 'kind', ['city', 'municipality']);

        Schema::create('barangays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('cities');
            $table->char('psgc_code', 10)->unique();
            $table->string('name', 150);
            $table->string('district', 100)->nullable();

            $table->index(['city_id', 'name']);
        });

        foreach (['provinces', 'cities', 'barangays'] as $table) {
            Constraints::check($table, "{$table}_psgc_code_digits", "`psgc_code` REGEXP '^[0-9]{10}$'");
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('province')->constrained('cities');
            $table->foreignId('barangay_id')->nullable()->after('city_id')->constrained('barangays');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('barangay_id');
            $table->dropConstrainedForeignId('city_id');
        });

        Schema::dropIfExists('barangays');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('provinces');
    }
};
