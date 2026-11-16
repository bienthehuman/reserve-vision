<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 6.2. `message` is what the renter wrote when asking for the viewing (at most 500 characters,
 * checked by the action). The landlord's answer stays in `landlord_note`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viewing_requests', function (Blueprint $table) {
            $table->text('message')->nullable()->after('preferred_at');
        });
    }

    public function down(): void
    {
        Schema::table('viewing_requests', function (Blueprint $table) {
            $table->dropColumn('message');
        });
    }
};
