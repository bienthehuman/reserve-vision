<?php

declare(strict_types=1);

use App\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
            // One user is one type (DATA_MODEL.md). Existing rows are renters.
            $table->string('type', 16)->default('renter')->after('phone');

            // People have a first name, an optional middle name, a last name and an optional suffix, never one
            // free-text name: "Joshua Gonzales" and "Joshua Gonzales Jr." are
            // different people (CONVENTIONS.md, "Names").
            $table->string('first_name', 100)->default('')->after('id');
            $table->string('middle_name', 100)->nullable()->after('first_name'); // many people have none
            $table->string('last_name', 100)->default('')->after('middle_name');
            // Validated by the NameSuffix enum and the form, not a CHECK: it is display
            // data, and a new value shouldn't need a migration.
            $table->string('suffix', 8)->nullable()->after('last_name');
        });

        // Existing rows (none in a fresh install): split the old name at its last space.
        DB::table('users')->orderBy('id')->each(function (object $user): void {
            $parts = preg_split('/\s+/', trim(is_string($user->name) ? $user->name : ''), -1, PREG_SPLIT_NO_EMPTY) ?: [''];
            $last = count($parts) > 1 ? array_pop($parts) : '';

            DB::table('users')->where('id', $user->id)->update([
                'first_name' => implode(' ', $parts),
                'last_name' => $last,
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
            $table->string('first_name', 100)->default(null)->change();
            $table->string('last_name', 100)->default(null)->change();
        });

        Constraints::in('users', 'type', ['renter', 'landlord']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
        });

        DB::table('users')->update(['name' => DB::raw("CONCAT_WS(' ', first_name, middle_name, last_name)")]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->default(null)->change();
            $table->dropColumn(['first_name', 'middle_name', 'last_name', 'suffix', 'phone', 'type']);
        });
    }
};
