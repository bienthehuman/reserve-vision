<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 6.3. One row per proof and reminder threshold (24 or 48 hours): the unique key is what makes the hourly
 * job send each reminder once, however many runs overlap. A new proof (after a rejection) starts again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proof_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_proof_id')->constrained('payment_proofs');
            $table->unsignedSmallInteger('threshold_hours');
            $table->timestamp('sent_at')->useCurrent();

            $table->unique(['payment_proof_id', 'threshold_hours']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_reminders');
    }
};
