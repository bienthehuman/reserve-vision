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
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('landlord_account_id');
            $table->string('name');
            $table->string('room_type', 16);
            // Money is unsigned integer centavos.
            $table->unsignedInteger('monthly_rate');
            $table->unsignedTinyInteger('deposit_months')->default(0);
            $table->unsignedTinyInteger('advance_months')->default(0);
            $table->unsignedInteger('reservation_fee')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign(['property_id', 'landlord_account_id'])
                ->references(['id', 'landlord_account_id'])->on('properties');
            $table->unique(['id', 'landlord_account_id']);
            $table->index(['landlord_account_id', 'property_id']);
        });

        Constraints::in('rooms', 'room_type', ['private', 'shared']);
        Constraints::check('rooms', 'rooms_monthly_rate_positive', '`monthly_rate` > 0');
        Constraints::check('rooms', 'rooms_reservation_fee_not_negative', '`reservation_fee` >= 0');
        Constraints::check('rooms', 'rooms_deposit_months_range', '`deposit_months` BETWEEN 0 AND 3');
        Constraints::check('rooms', 'rooms_advance_months_range', '`advance_months` BETWEEN 0 AND 3');
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
