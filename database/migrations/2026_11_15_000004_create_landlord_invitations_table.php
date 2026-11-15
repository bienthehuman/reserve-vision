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
        Schema::create('landlord_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_account_id')->constrained('landlord_accounts');
            $table->string('email');
            $table->string('role', 16);
            // SHA-256 hex of the emailed token; the token itself is never stored.
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['landlord_account_id', 'email']);
        });

        Constraints::in('landlord_invitations', 'role', ['owner', 'caretaker']);
    }

    public function down(): void
    {
        Schema::dropIfExists('landlord_invitations');
    }
};
