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
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('landlord_account_id');
            $table->string('path'); // private disk
            $table->unsignedInteger('amount'); // centavos, as the renter typed it
            $table->string('reference_no')->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('uploaded_at')->useCurrent();

            $table->foreign(['reservation_id', 'landlord_account_id'])
                ->references(['id', 'landlord_account_id'])->on('reservations');
            $table->index(['reservation_id', 'status']);
        });

        Constraints::in('payment_proofs', 'status', ['pending', 'accepted', 'rejected']);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proofs');
    }
};
