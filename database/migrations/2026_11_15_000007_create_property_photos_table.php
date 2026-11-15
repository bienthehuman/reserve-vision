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
        Schema::create('property_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('landlord_account_id');
            $table->string('path');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('status', 16)->default('processing');
            $table->timestamps();

            $table->foreign(['property_id', 'landlord_account_id'])
                ->references(['id', 'landlord_account_id'])->on('properties');
            $table->index(['property_id', 'sort']);
        });

        Constraints::in('property_photos', 'status', ['processing', 'ready', 'failed']);
    }

    public function down(): void
    {
        Schema::dropIfExists('property_photos');
    }
};
