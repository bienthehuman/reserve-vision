<?php

declare(strict_types=1);

use App\Actions\AddSpace;
use App\Actions\ArchiveProperty;
use App\Actions\ArchiveRoom;
use App\Actions\ArchiveSpace;
use App\Actions\RenameSpace;
use App\Actions\UnarchiveSpace;
use App\Actions\UpdateRoom;
use App\Data\RoomDetails;
use App\Exceptions\Domain\CannotArchive;
use App\Exceptions\Domain\RoomNotReservable;
use App\Models\Property;
use App\Models\Room;
use App\Models\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

// Slice 4.1, in the style of tests/Feature/Reservations/LockingTest.php: every landlord action that
// archives a room or space, changes a room's fee or type, or adds or renames spaces takes the ROOM ROW
// LOCK FIRST (the lock ReserveSpace takes), then checks with locking reads, so a renter can't slip a hold
// onto a space between the check and the archive. A property archive takes every room's lock, by id, then
// the property's (ReserveSpace's order: room, then a locking read of the property). The real race over
// parallel HTTP is in tests/Concurrency.

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/**
 * @return list<string> the SQL the closure ran, lower-cased, in order
 */
function actionQueries(Closure $run): array
{
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $run();

    return $queries;
}

/** The first statement is the room's FOR UPDATE; every SELECT on spaces or reservations after it is a locking read. */
function expectRoomLockedFirst(array $queries): void
{
    expect($queries[0])->toMatch('/^select \* from `rooms` where `rooms`\.`id` = \? limit 1 for update$/');

    foreach ($queries as $sql) {
        if (str_starts_with($sql, 'select') && (str_contains($sql, 'from `reservations`') || str_contains($sql, 'from `spaces`'))) {
            expect($sql)->toMatch('/ (for update|lock in share mode)$/')->and($sql)->not->toMatch('/\(\s*select/');
        }
    }
}

it('takes the room lock first when archiving a space, then reads only with locking reads', function (): void {
    [, , $room] = ownerWithRoom(beds: 3);
    $space = Space::query()->where('room_id', $room->id)->firstOrFail();

    $queries = actionQueries(fn () => app(ArchiveSpace::class)->handle($space));

    expectRoomLockedFirst($queries);
    expect(implode("\n", $queries))->toContain('from `reservations`')->toContain('update `spaces`');
});

it('takes the room lock first when archiving a room', function (): void {
    [, , $room] = ownerWithRoom(beds: 3);

    $queries = actionQueries(fn () => app(ArchiveRoom::class)->handle($room));

    expectRoomLockedFirst($queries);
    expect(implode("\n", $queries))->toContain('from `reservations`')->toContain('update `rooms`');
});

it('takes the room lock first when changing a fee or the type, adding a space, renaming one, or bringing one back', function (): void {
    [, , $room] = ownerWithRoom(beds: 3);
    $space = Space::query()->where('room_id', $room->id)->firstOrFail();
    $details = new RoomDetails('Room 1', $room->room_type, 650000, 999900, 1, 1, null);

    expectRoomLockedFirst(actionQueries(fn () => app(UpdateRoom::class)->handle($room, $details)));
    expectRoomLockedFirst(actionQueries(fn () => app(AddSpace::class)->handle($room)));
    expectRoomLockedFirst(actionQueries(fn () => app(RenameSpace::class)->handle($space, 'Window')));

    $space->forceFill(['archived_at' => now()])->save();
    expectRoomLockedFirst(actionQueries(fn () => app(UnarchiveSpace::class)->handle($space)));
});

it('takes every room lock, by id, before the property lock, when archiving a property', function (): void {
    [, $property, $first] = ownerWithRoom(beds: 2);
    $second = Room::factory()->for($property)->create();

    $queries = actionQueries(fn () => app(ArchiveProperty::class)->handle($property));

    expect($queries[0])->toMatch('/^select `id` from `rooms` where `property_id` = \? order by `id` asc for update$/');
    $propertyLock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from `properties`') && str_ends_with($sql, 'for update'));
    $spacesRead = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from `spaces`'));
    expect($propertyLock)->toBeInt()->toBeGreaterThan(0)->and($spacesRead)->toBeGreaterThan($propertyLock);
    expect($first->id)->toBeLessThan($second->id);
});

it('sees a hold that committed first: the archive is refused, not applied over it', function (): void {
    [, , $room] = ownerWithRoom();
    $renter = User::factory()->renter()->create();

    // A plain read first, so the transaction has a snapshot (every test runs inside one): the pitfall in ReserveSpace::reserve().
    Room::query()->findOrFail($room->id);
    $hold = reserve($renter, $room, '2027-04-01');

    expect(fn () => app(ArchiveRoom::class)->handle($room))->toThrow(CannotArchive::class, "#{$hold->id}");
    expect($room->fresh()->archived_at)->toBeNull();
});

it('refuses a hold after the archive: the room lock makes the two a sequence', function (): void {
    [, , $room] = ownerWithRoom(beds: 2);
    app(ArchiveRoom::class)->handle($room);

    expect(fn () => reserve(User::factory()->renter()->create(), $room, '2027-04-01'))->toThrow(RoomNotReservable::class);
});

it('keeps the property archive out of ReserveSpace\'s way: it can\'t archive over a hold on any room', function (): void {
    [, $property] = ownerWithRoom();
    $other = Room::factory()->for($property)->create();
    $hold = reserve(User::factory()->renter()->create(), $other, '2027-04-01');

    expect(fn () => app(ArchiveProperty::class)->handle($property))->toThrow(CannotArchive::class, "#{$hold->id}");
    expect(Property::query()->findOrFail($property->id)->archived_at)->toBeNull();
});
