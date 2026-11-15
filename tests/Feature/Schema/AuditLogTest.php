<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// ADR 0008: audit_logs is append-only for `app`. UPDATE and DELETE are rejected by
// triggers (SQLSTATE 45000, driver code 1644); TRUNCATE and DROP TRIGGER need
// privileges `app` doesn't have (1142).

it('lets the app user insert and read audit rows', function (): void {
    $log = AuditLog::factory()->create(['action' => 'ConfirmReservation', 'before' => ['status' => 'proof_uploaded']]);

    $found = AuditLog::query()->findOrFail($log->id);

    expect($found->action)->toBe('ConfirmReservation')
        ->and($found->before)->toBe(['status' => 'proof_uploaded'])
        ->and($found->created_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('rejects UPDATE on audit_logs', function (): void {
    $log = AuditLog::factory()->create();

    expect(dbErrorCode(fn () => DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'edited'])))->toBe(1644)
        ->and(dbErrorCode(fn () => DB::table('audit_logs')->update(['action' => 'edited'])))->toBe(1644)
        ->and(AuditLog::query()->findOrFail($log->id)->action)->toBe('ConfirmReservation');
});

it('rejects an upsert that would update an existing row', function (): void {
    $log = AuditLog::factory()->create();

    expect(dbErrorCode(fn () => DB::table('audit_logs')->upsert(
        [['id' => $log->id, 'action' => 'edited', 'subject_type' => 'reservation', 'subject_id' => 1]],
        ['id'],
        ['action'],
    )))->toBe(1644);
});

it('rejects DELETE on audit_logs', function (): void {
    $log = AuditLog::factory()->create();

    expect(dbErrorCode(fn () => DB::table('audit_logs')->where('id', $log->id)->delete()))->toBe(1644)
        ->and(dbErrorCode(fn () => DB::table('audit_logs')->delete()))->toBe(1644)
        ->and(AuditLog::query()->whereKey($log->id)->exists())->toBeTrue();
});

it('rejects Eloquent save() on an existing row and delete()', function (): void {
    $log = AuditLog::factory()->create();

    $log->action = 'edited';

    expect(dbErrorCode(fn () => $log->save()))->toBe(1644)
        ->and(dbErrorCode(fn () => $log->delete()))->toBe(1644);
});

it('denies TRUNCATE, DROP and removing the triggers to the app user', function (string $sql): void {
    expect(dbErrorCode(fn () => DB::unprepared($sql)))->toBe(1142);
})->with([
    'TRUNCATE' => 'TRUNCATE TABLE audit_logs',
    'DROP TABLE' => 'DROP TABLE audit_logs',
    'DROP TRIGGER update' => 'DROP TRIGGER audit_logs_no_update',
    'DROP TRIGGER delete' => 'DROP TRIGGER audit_logs_no_delete',
]);
