<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concurrency\Support\Http;
use Tests\Concurrency\Support\Racer;

/**
 * The concurrency suite (CONVENTIONS.md "Tests"; run with `composer test:concurrency`).
 *
 * Unlike Feature tests, nothing here runs in-process and nothing is wrapped in
 * a transaction: the server is a separate, multi-threaded process (started by
 * bin/test-concurrency) that must see the fixtures, so they're committed as
 * `app`. Server and suite share one schema, `reservevision_concurrency`,
 * wiped by migrate:fresh as `migrator` once at the start. Each test makes its
 * own rooms and renters, so tests don't depend on each other.
 */
abstract class ConcurrencyTestCase extends BaseTestCase
{
    private static bool $prepared = false;

    protected Http $http;

    protected function setUp(): void
    {
        parent::setUp();

        $baseUrl = getenv('CONCURRENCY_BASE_URL');
        $this->http = new Http(is_string($baseUrl) && $baseUrl !== '' ? rtrim($baseUrl, '/') : 'http://127.0.0.1:8001');

        if (! self::$prepared) {
            $this->prepareSchema();
            self::$prepared = true;
        }
    }

    /**
     * The server and the suite must use the same schema: fail clearly if the
     * server can't see a room this process has just committed.
     */
    protected function assertServerSees(Room $room): void
    {
        $page = (new Racer(new User, 'probe'))->get("/rooms/{$room->id}");
        $this->http->fire([$page]);

        if ($page->status !== 200 || ! str_contains($page->body, $room->name)) {
            throw new RuntimeException(sprintf(
                'The server at %s answered HTTP %d for room %d (%s), which this suite just committed to [%s]. Is the server pointed at the same schema? bin/test-concurrency starts one that is.',
                $this->http->baseUrl,
                $page->status,
                $room->id,
                $room->name,
                DB::connection()->getDatabaseName(),
            ));
        }
    }

    private function prepareSchema(): void
    {
        $database = DB::connection('mysql_migrator')->getDatabaseName();

        // migrate:fresh drops every table: refuse anything but the concurrency schema.
        if (preg_match('/_concurrency$/', $database) !== 1) {
            throw new RuntimeException("Refusing to migrate:fresh [{$database}]: the concurrency schema must end in _concurrency.");
        }

        Artisan::call('migrate:fresh', ['--database' => 'mysql_migrator', '--force' => true]);
        DB::purge('mysql_migrator');
    }
}
