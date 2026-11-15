<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Where the renter sends the reservation fee (ACCESS.md flow B). Null while a draft; required to publish (4.3).
            $table->text('payment_instructions')->nullable()->after('house_rules');
            // Set the first time the property is published and never cleared: from then on its public URL
            // (/listings/{city}/{slug}) exists, so the slug and the city are locked (slice 4.1; 4.3 sets it).
            $table->timestamp('first_published_at')->nullable()->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['payment_instructions', 'first_published_at']);
        });
    }
};
