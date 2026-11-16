<?php

declare(strict_types=1);

use App\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 5.3. `mime_type` is the type the server detected from the file's content when the renter
 * uploaded it (never the browser's claim): the proof is streamed back with it. `path` is now
 * `proofs/<uuid>.<ext>` on the private disk. Rows from before 5.3 (only demo data) were JPEG
 * placeholders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->string('mime_type', 32)->nullable()->after('path');
        });

        DB::table('payment_proofs')->whereNull('mime_type')->update(['mime_type' => 'image/jpeg']);

        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->string('mime_type', 32)->nullable(false)->change();
        });

        Constraints::in('payment_proofs', 'mime_type', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `payment_proofs` DROP CONSTRAINT `payment_proofs_mime_type_valid`');

        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->dropColumn('mime_type');
        });
    }
};
