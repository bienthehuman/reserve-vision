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
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('space_id');
            $table->unsignedBigInteger('landlord_account_id');
            $table->foreignId('renter_id')->constrained('users');
            $table->date('move_in');
            $table->date('move_out'); // exclusive
            $table->string('status', 16);
            $table->unsignedInteger('reservation_fee'); // snapshot, centavos
            $table->timestamp('hold_expires_at')->nullable();
            $table->unsignedTinyInteger('proof_rejections')->default(0);
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('fee_refunded')->nullable();
            $table->text('refund_note')->nullable();
            $table->timestamps();

            $table->foreign(['space_id', 'landlord_account_id'])
                ->references(['id', 'landlord_account_id'])->on('spaces');
            $table->unique(['id', 'landlord_account_id']);

            // Overlap checks and availability search.
            $table->index(['space_id', 'status', 'move_in', 'move_out'], 'reservations_space_status_dates_index');
            // Open-reservation limits.
            $table->index(['renter_id', 'status']);
            // Landlord dashboard.
            $table->index(['landlord_account_id', 'status']);
            // ExpireHolds job.
            $table->index(['status', 'hold_expires_at']);
        });

        Constraints::check('reservations', 'reservations_move_in_before_move_out', '`move_in` < `move_out`');
        Constraints::in('reservations', 'status', [
            'held', 'proof_uploaded', 'confirmed', 'active', 'expired', 'declined', 'cancelled', 'ended',
        ]);
        Constraints::check('reservations', 'reservations_proof_rejections_range', '`proof_rejections` BETWEEN 0 AND 2');
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
