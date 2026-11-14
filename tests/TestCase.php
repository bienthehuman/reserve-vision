<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use RuntimeException;

/**
 * Test DB strategy (ADR 0008): tests connect as the `app` user, which has no
 * DDL. So instead of RefreshDatabase, the schema is migrated once per process
 * as `migrator` (connection `mysql_migrator`), and each test that touches the
 * database runs inside a transaction (DatabaseTransactions, see Pest.php).
 *
 * Parallel runs (`pest --parallel --processes=4`): worker N uses its own schema,
 * `<DB_DATABASE>_N` (reservevision_test_1 … _4). Laravel's own per-process
 * databases are switched off in phpunit.xml (LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES)
 * because they need CREATE DATABASE, which neither user has. The schemas are
 * created up front by database/sql/create-users.sql, run as root.
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $migrated = false;

    /**
     * Point both connections at this worker's schema before anything connects
     * (DatabaseTransactions opens its transaction right after this).
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $token = ParallelTesting::token();

        if ($token !== false) {
            /** @var string $base */
            $base = config('database.connections.mysql.database');

            config([
                'database.connections.mysql.database' => "{$base}_{$token}",
                'database.connections.mysql_migrator.database' => "{$base}_{$token}",
            ]);
        }

        return $app;
    }

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
        if (preg_match('/_test(_\d+)?$/', $database) !== 1) {
            throw new RuntimeException("Refusing to migrate:fresh [{$database}]: test schemas must end in _test or _test_<worker>.");
        }

        Artisan::call('migrate:fresh', [
            '--database' => 'mysql_migrator',
            '--force' => true,
        ]);

        // Close the DDL connection so the suite itself only ever talks as `app`.
        DB::purge('mysql_migrator');
    }
}
