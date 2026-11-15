<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
        });

        Schema::create('property_amenity', function (Blueprint $table) {
            $table->foreignId('property_id')->constrained('properties');
            $table->foreignId('amenity_id')->constrained('amenities');

            $table->primary(['property_id', 'amenity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_amenity');
        Schema::dropIfExists('amenities');
    }
};
