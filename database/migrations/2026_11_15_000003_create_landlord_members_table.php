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
        Schema::create('landlord_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_account_id')->constrained('landlord_accounts');
            // Unique: a landlord user belongs to exactly one account.
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->string('role', 16);
            $table->timestamps();
        });

        Constraints::in('landlord_members', 'role', ['owner', 'caretaker']);
    }

    public function down(): void
    {
        Schema::dropIfExists('landlord_members');
    }
};
