<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

// ReserveSpace::reserve() explains why: under REPEATABLE READ a plain SELECT
// inside the transaction fixes a snapshot that can hide the row the previous
// lock holder inserted. This pins the rules down so a refactor can't quietly
// break them. Real parallel requests are slice 2.5 (T1, T2, T8b).

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/**
 * @return list<string> the SQL ReserveSpace ran, lower-cased
 */
function reserveSpaceQueries(Closure $reserve): array
{
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $reserve();

    return $queries;
}

it('locks the renter row, then the room row, before any other statement', function (): void {
    $renter = User::factory()->renter()->create();
    $room = reservableRoom();

    $queries = reserveSpaceQueries(fn () => reserve($renter, $room, '2027-04-01'));

    expect($queries[0])->toMatch('/^select \* from `users` where `users`\.`id` = \? limit 1 for update$/')
        ->and($queries[1])->toMatch('/^select \* from `rooms` where `rooms`\.`id` = \? limit 1 for update$/');
});

it('makes every SELECT a locking read and ends with the insert', function (): void {
    $renter = User::factory()->renter()->create();
    $room = reservableRoom();

    $queries = reserveSpaceQueries(fn () => reserve($renter, $room, '2027-04-01'));
    $selects = array_values(array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'select')));

    expect($selects)->not->toBeEmpty();

    foreach ($selects as $sql) {
        expect($sql)->toMatch('/ (for update|lock in share mode)$/')
            ->and($sql)->not->toMatch('/\(\s*select/'); // no subqueries: they would be non-locking reads
    }

    expect(end($queries))->toStartWith('insert into `reservations`');
});
