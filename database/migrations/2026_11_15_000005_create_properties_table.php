<?php

declare(strict_types=1);

use App\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_account_id')->constrained('landlord_accounts');
            $table->string('kind', 16);
            $table->string('name');
            $table->string('slug');
            $table->string('city');
            $table->string('city_slug');
            $table->string('barangay')->nullable();
            $table->string('address');
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->string('gender_policy', 16)->default('any');
            $table->text('house_rules')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['city_slug', 'slug']); // /listings/{city}/{slug}
            // Target of the composite foreign keys of rooms, photos and viewing requests.
            $table->unique(['id', 'landlord_account_id']);
            $table->index(['landlord_account_id', 'is_published']);
        });

        Constraints::in('properties', 'kind', ['apartment', 'boarding_house']);
        Constraints::in('properties', 'gender_policy', ['any', 'male_only', 'female_only']);
        Constraints::check('properties', 'properties_lat_in_range', '`lat` IS NULL OR `lat` BETWEEN -90 AND 90');
        Constraints::check('properties', 'properties_lng_in_range', '`lng` IS NULL OR `lng` BETWEEN -180 AND 180');
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
