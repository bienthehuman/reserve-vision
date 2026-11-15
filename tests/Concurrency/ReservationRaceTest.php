<?php

declare(strict_types=1);

use App\Exceptions\Domain\HoldLimitReached;
use App\Exceptions\Domain\NoSpaceAvailable;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Space;
use App\Models\User;
use App\Support\BusinessDate;
use Tests\Concurrency\Support\Exchange;
use Tests\Concurrency\Support\Race;
use Tests\Concurrency\Support\Racer;

/*
| T1, T2 and T8b (BUILD_PLAN.md): renters race through real, parallel HTTP
| requests against a running multi-threaded server, and the database must
| come out right every time. Each scenario runs ROUNDS times with fresh rooms
| and renters, so a lucky pass is unlikely; every round proves the requests
| really overlapped inside the server (Race::assertRanConcurrently()).
*/

const ROUNDS = 5;

/** A move-in date inside the window for every round of the run. */
function raceMoveIn(): string
{
    return BusinessDate::today()->addDays(14)->toDateString();
}

/**
 * @return list<Racer> renters, logged in, each with their own session
 */
function racers(int $count, string $prefix = 'renter'): array
{
    $racers = [];
    foreach (range(1, $count) as $i) {
        $racers[] = new Racer(User::factory()->create(), sprintf('%s-%02d', $prefix, $i));
    }

    return $racers;
}

function listedRoom(?Property $property = null, int $beds = 0): Room
{
    $factory = $beds > 0 ? Room::factory()->shared(beds: $beds) : Room::factory();

    return $factory->for($property ?? Property::factory()->published()->create())->create();
}

function reserveRequest(Racer $racer, Room $room): Exchange
{
    return $racer->postJson("/rooms/{$room->id}/reservations", ['move_in' => raceMoveIn(), 'months' => 6]);
}

/**
 * "hold on Bed B" or the rejection message, for the timeline.
 *
 * @return Closure(Exchange): string
 */
function outcomeFor(): Closure
{
    return function (Exchange $exchange): string {
        if ($exchange->status === 201) {
            $spaceId = $exchange->json()['reservation']['space_id'] ?? null;
            $space = Space::query()->with('room')->find($spaceId);

            return $space === null ? 'hold' : "hold on {$space->label} ({$space->room->name})";
        }

        return $exchange->message() ?: substr($exchange->body, 0, 80);
    };
}

function nextRound(string $scenario): int
{
    static $rounds = [];

    return $rounds[$scenario] = ($rounds[$scenario] ?? 0) + 1;
}

/**
 * Every response is a hold (201) or one of the expected rejections (422 with
 * exactly that message); anything else (a 500 from a deadlock that ran out of
 * retries, a 419, a 429) fails the run.
 *
 * @param  list<string>  $allowedMessages
 */
function expectOnlyHoldsAnd(Race $race, array $allowedMessages): void
{
    foreach ($race->exchanges as $exchange) {
        if ($exchange->status === 201) {
            continue;
        }

        expect($exchange->status)->toBe(422, 'Unexpected response: '.$exchange->describe())
            ->and($allowedMessages)->toContain($exchange->message());
    }
}

/** @return list<int> space ids of the blocking reservations in these rooms */
function blockingSpaceIds(Room ...$rooms): array
{
    return Reservation::query()
        ->whereIn('space_id', Space::query()->whereIn('room_id', array_map(fn (Room $r): int => $r->id, $rooms))->select('id'))
        ->blocking()
        ->orderBy('space_id')
        ->pluck('space_id')
        ->all();
}

it('T1: 10 renters race for one single-space room', function (): void {
    $room = listedRoom();
    $this->assertServerSees($room);
    $renters = racers(10);
    Racer::logInAll($this->http, $renters);

    $race = Race::run(
        $this->http,
        sprintf('T1 round %d/%d: 10 renters, 1 single-space room, same dates', nextRound('T1'), ROUNDS),
        array_map(fn (Racer $racer): Exchange => reserveRequest($racer, $room), $renters),
        outcomeFor(),
    );

    $noSpace = NoSpaceAvailable::forDates()->getMessage();
    expectOnlyHoldsAnd($race, [$noSpace]);

    expect(blockingSpaceIds($room))->toHaveCount(1, 'DOUBLE-BOOKED: '.count(blockingSpaceIds($room)).' blocking reservations on one space')
        ->and($race->withStatus(201))->toHaveCount(1)
        ->and($race->withStatus(422))->toHaveCount(9);
})->repeat(ROUNDS);

it('T2: 6 renters race for a 4-bed shared room', function (): void {
    $room = listedRoom(beds: 4);
    $this->assertServerSees($room);
    $renters = racers(6);
    Racer::logInAll($this->http, $renters);

    $race = Race::run(
        $this->http,
        sprintf('T2 round %d/%d: 6 renters, a 4-bed shared room, same dates', nextRound('T2'), ROUNDS),
        array_map(fn (Racer $racer): Exchange => reserveRequest($racer, $room), $renters),
        outcomeFor(),
    );

    expectOnlyHoldsAnd($race, [NoSpaceAvailable::forDates()->getMessage()]);

    $spaceIds = blockingSpaceIds($room);
    $holdSpaceIds = array_map(fn (Exchange $e): mixed => $e->json()['reservation']['space_id'] ?? null, $race->withStatus(201));

    expect($spaceIds)->toHaveCount(4)
        ->and(array_unique($spaceIds))->toHaveCount(4, 'DOUBLE-BOOKED: two holds share a bed: '.implode(', ', $spaceIds))
        ->and(array_unique($holdSpaceIds))->toHaveCount(4)
        ->and($race->withStatus(201))->toHaveCount(4)
        ->and($race->withStatus(422))->toHaveCount(2);
})->repeat(ROUNDS);

it('T8b: one renter fires 3 parallel reserve requests on different properties', function (): void {
    $rooms = [listedRoom(), listedRoom(), listedRoom()];
    $this->assertServerSees($rooms[0]);
    [$renter] = racers(1);
    Racer::logInAll($this->http, [$renter]);

    $race = Race::run(
        $this->http,
        sprintf('T8b round %d/%d: one renter, 3 rooms in 3 different properties', nextRound('T8b-3'), ROUNDS),
        array_map(fn (Room $room): Exchange => reserveRequest($renter, $room), $rooms),
        outcomeFor(),
    );

    expectOnlyHoldsAnd($race, [HoldLimitReached::overall(2)->getMessage()]);

    $open = Reservation::query()->openForRenter($renter->user)->count();
    expect($open)->toBeLessThanOrEqual(2, "LIMIT BROKEN: the renter has {$open} open reservations")
        ->and($race->withStatus(201))->toHaveCount(2)
        ->and($race->withStatus(422))->toHaveCount(1);
})->repeat(ROUNDS);

it('T8b: one renter fires 2 parallel reserve requests on rooms in the same property', function (): void {
    $property = Property::factory()->published()->create();
    $rooms = [listedRoom($property), listedRoom($property)];
    $this->assertServerSees($rooms[0]);
    [$renter] = racers(1);
    Racer::logInAll($this->http, [$renter]);

    $race = Race::run(
        $this->http,
        sprintf('T8b round %d/%d: one renter, 2 rooms in the same property', nextRound('T8b-2'), ROUNDS),
        array_map(fn (Room $room): Exchange => reserveRequest($renter, $room), $rooms),
        outcomeFor(),
    );

    expectOnlyHoldsAnd($race, [HoldLimitReached::inProperty()->getMessage()]);

    $open = Reservation::query()->openForRenter($renter->user)->count();
    expect($open)->toBeLessThanOrEqual(1, "LIMIT BROKEN: the renter has {$open} open reservations in one property")
        ->and($race->withStatus(201))->toHaveCount(1)
        ->and($race->withStatus(422))->toHaveCount(1);
})->repeat(ROUNDS);
