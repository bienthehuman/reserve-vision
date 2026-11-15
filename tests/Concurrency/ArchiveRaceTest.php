<?php

declare(strict_types=1);

use App\Exceptions\Domain\NoSpaceAvailable;
use App\Exceptions\Domain\RoomNotReservable;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use Tests\Concurrency\Support\Exchange;
use Tests\Concurrency\Support\Race;
use Tests\Concurrency\Support\Racer;

/*
| Slice 4.1: the owner archives a single-space room at the very moment renters reserve it, through real,
| parallel HTTP requests (see ReservationRaceTest.php for the harness). Archiving and reserving both take
| the room row's lock (ArchiveRoom, ReserveSpace), so they run one after the other, and exactly one of them wins:
|
|  - a hold commits first: the archive sees it (a locking read) and is refused, 422 naming the reservation;
|  - the archive commits first: the room is archived, and every reserve is refused (404 if the room page's
|    lookup already saw it archived, 422 "can't be reserved" if it got past that and the lock showed it).
|
| Never both: an archived room that a renter holds, or a room that is neither archived nor held. Each round
| fires one archive and three renters. The archive is the quicker request (no rate limiter), so alone it
| would always win; each round sends it a little later than the renters (0, 4, 8, … ms, Exchange::after()),
| which sweeps the race window and lets a hold get in first in the later rounds.
*/

const ARCHIVE_ROUNDS = 6;

it('archive versus reserve: the owner archives a single-space room while 3 renters reserve it', function (): void {
    $owner = new Racer(landlordUser(), 'owner');
    $room = Room::factory()->for(Property::factory()->published()->create(['landlord_account_id' => accountIdOf($owner->user)]))->create();
    $this->assertServerSees($room);
    $renters = racers(3);
    Racer::logInAll($this->http, [$owner, ...$renters]);

    $round = nextRound('Archive');
    $archive = $owner->postJson("/landlord/rooms/{$room->id}/archive", [])->after(($round - 1) * 0.004);
    $reserves = array_map(fn (Racer $renter): Exchange => reserveRequest($renter, $room), $renters);

    $race = Race::run(
        $this->http,
        sprintf('Archive vs reserve round %d/%d: 1 owner archives a single-space room, 3 renters reserve it', $round, ARCHIVE_ROUNDS),
        [$archive, ...$reserves],
        fn (Exchange $e): string => match (true) {
            $e === $archive && $e->status === 302 => 'room archived',
            $e->status === 201 => 'hold created',
            $e->status === 422 => $e->message(),
            default => 'HTTP '.$e->status,
        },
    );

    $archived = $room->fresh()->archived_at !== null;
    $holds = Reservation::query()->whereIn('space_id', $room->spaces()->select('id'))->blocking()->count();

    expect($archived && $holds > 0)->toBeFalse("BOTH WON: the room is archived and a renter holds its space ({$holds} blocking reservation)")
        ->and($archived || $holds === 1)->toBeTrue('NEITHER WON: the room is not archived and nobody holds its space')
        ->and($holds)->toBeLessThanOrEqual(1);

    $created = array_values(array_filter($reserves, fn (Exchange $e): bool => $e->status === 201));

    if ($archived) {
        expect($archive->status)->toBe(302)->and($created)->toBeEmpty();

        foreach ($reserves as $reserve) {
            expect($reserve->status)->toBeIn([404, 422], 'Unexpected response: '.$reserve->describe());
            if ($reserve->status === 422) {
                expect($reserve->message())->toBe(RoomNotReservable::make()->getMessage());
            }
        }
    } else {
        $reservationId = $room->spaces()->firstOrFail()->reservations()->firstOrFail()->id;

        expect($created)->toHaveCount(1)
            ->and($archive->status)->toBe(422, 'Unexpected response: '.$archive->describe())
            ->and($archive->message())->toContain("#{$reservationId}")->toContain("can't be archived");

        foreach (array_filter($reserves, fn (Exchange $e): bool => $e->status !== 201) as $reserve) {
            expect($reserve->status)->toBe(422, 'Unexpected response: '.$reserve->describe())
                ->and($reserve->message())->toBe(NoSpaceAvailable::forDates()->getMessage());
        }
    }

    expect($race->exchanges)->toHaveCount(4);
})->repeat(ARCHIVE_ROUNDS);
