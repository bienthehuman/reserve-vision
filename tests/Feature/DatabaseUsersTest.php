<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// ADR 0008: the suite runs as `app`, which may change data but never the schema.

it('runs the test suite as the app user', function (): void {
    $user = DB::selectOne('SELECT CURRENT_USER() AS user')->user;

    expect(DB::getDefaultConnection())->toBe('mysql')
        ->and($user)->toStartWith('app@')
        ->and(DB::getDatabaseName())->toEndWith('_test');
});

it('lets the app user read and write data', function (): void {
    DB::table('cache')->insert(['key' => 'probe', 'value' => 'ok', 'expiration' => 0]);
    DB::table('cache')->where('key', 'probe')->update(['value' => 'still ok']);

    expect(DB::table('cache')->where('key', 'probe')->value('value'))->toBe('still ok');

    DB::table('cache')->where('key', 'probe')->delete();
    expect(DB::table('cache')->where('key', 'probe')->exists())->toBeFalse();
});

it('denies DDL to the app user', function (string $sql): void {
    expect(fn () => DB::statement($sql))
        ->toThrow(function (QueryException $e): void {
            // 1142: <command> denied to user (MySQL and MariaDB)
            expect($e->errorInfo[1] ?? null)->toBe(1142);
        });
})->with([
    'CREATE TABLE' => 'CREATE TABLE ddl_probe (id INT PRIMARY KEY)',
    'DROP TABLE' => 'DROP TABLE cache',
    'ALTER TABLE' => 'ALTER TABLE cache ADD COLUMN ddl_probe INT',
    'TRUNCATE' => 'TRUNCATE TABLE cache',
]);
