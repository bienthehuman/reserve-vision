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
        Schema::create('viewing_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('landlord_account_id');
            $table->foreignId('renter_id')->constrained('users');
            $table->timestamp('preferred_at');
            $table->string('status', 16)->default('requested');
            $table->text('landlord_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->foreign(['property_id', 'landlord_account_id'])
                ->references(['id', 'landlord_account_id'])->on('properties');
            $table->index(['landlord_account_id', 'status']);
            $table->index(['renter_id', 'status']);
        });

        Constraints::in('viewing_requests', 'status', ['requested', 'accepted', 'declined', 'done', 'cancelled']);
    }

    public function down(): void
    {
        Schema::dropIfExists('viewing_requests');
    }
};
