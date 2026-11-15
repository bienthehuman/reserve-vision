<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only (ADR 0008): BEFORE UPDATE and BEFORE DELETE triggers signal an
 * error, so `app` (which has no TRIGGER or DROP privilege, hence can neither
 * remove them nor TRUNCATE) can only INSERT and SELECT.
 *
 * Creating triggers while binary logging is on (the MySQL 8.4 default) needs
 * `log_bin_trust_function_creators=1`, set in compose.yaml, compose.prod.yaml
 * and the CI workflow.
 */
return new class extends Migration
{
    private const int ER_BINLOG_CREATE_ROUTINE_NEED_SUPER = 1419;

    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // No foreign keys: history must outlive and never block changes to what it describes.
            $table->unsignedBigInteger('actor_id')->nullable(); // null: the system
            $table->unsignedBigInteger('landlord_account_id')->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['landlord_account_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('actor_id');
        });

        try {
            foreach (['UPDATE' => 'updated', 'DELETE' => 'deleted'] as $event => $verb) {
                DB::unprepared(
                    'CREATE TRIGGER `audit_logs_no_'.strtolower($event).'` BEFORE '.$event.' ON `audit_logs` '
                    ."FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs rows cannot be {$verb}'"
                );
            }
        } catch (QueryException $e) {
            // Don't leave a table without its protection behind.
            Schema::dropIfExists('audit_logs');

            if (($e->errorInfo[1] ?? null) === self::ER_BINLOG_CREATE_ROUTINE_NEED_SUPER) {
                throw new RuntimeException(
                    'Cannot create the audit_logs triggers: binary logging is on and log_bin_trust_function_creators is off. '
                    .'Start the database server with --log-bin-trust-function-creators=1 (see ADR 0008).',
                    previous: $e,
                );
            }

            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs'); // drops its triggers too
    }
};
