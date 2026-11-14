<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Test DB strategy (ADR 0008): tests connect as the `app` user, which has no
 * DDL. So instead of RefreshDatabase, the schema is migrated once per run as
 * `migrator` (connection `mysql_migrator`), and each test that touches the
 * database runs inside a transaction (DatabaseTransactions, see Pest.php).
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$migrated) {
            $this->migrateOnceAsMigrator();
            self::$migrated = true;
        }
    }

    private function migrateOnceAsMigrator(): void
    {
        /** @var string $database */
        $database = config('database.connections.mysql_migrator.database');

        // migrate:fresh drops every table: refuse anything that isn't a test schema.
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to migrate:fresh [{$database}]: test schemas must end in _test.");
        }

        Artisan::call('migrate:fresh', [
            '--database' => 'mysql_migrator',
            '--force' => true,
        ]);

        // Close the DDL connection so the suite itself only ever talks as `app`.
        DB::purge('mysql_migrator');
    }
}
