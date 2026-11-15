<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spaces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('landlord_account_id');
            $table->string('label');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign(['room_id', 'landlord_account_id'])
                ->references(['id', 'landlord_account_id'])->on('rooms');
            $table->unique(['room_id', 'label']);
            $table->unique(['id', 'landlord_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spaces');
    }
};
