<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Enums\RoomType;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// Slice 4.1: adding and editing rooms (name, type, rate, fee, deposit, advance) and their spaces.

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/** @return list<string> */
function spaceLabels(Room $room): array
{
    return Space::query()->where('room_id', $room->id)->orderBy('id')->pluck('label')->all();
}

it('adds a private room with its one "Whole room" space, money in centavos, and one audit row', function (): void {
    [$owner, $property] = ownerWithRoom();

    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/rooms", roomForm())->assertRedirect("/landlord/properties/{$property->id}");

    $room = Room::query()->where('name', 'Room 2')->sole();
    expect($room->room_type)->toBe(RoomType::Private)
        ->and($room->monthly_rate)->toBe(650000)->and($room->reservation_fee)->toBe(150050)
        ->and($room->deposit_months)->toBe(1)->and($room->advance_months)->toBe(2)
        ->and($room->landlord_account_id)->toBe($property->landlord_account_id)
        ->and(spaceLabels($room))->toBe(['Whole room']);

    $row = auditRows('room.created')->sole();
    expect(json_decode($row->after, true))->toMatchArray(['name' => 'Room 2', 'monthly_rate' => 650000, 'spaces' => ['Whole room']])
        ->and(auditRows())->toHaveCount(1);
});

it('adds a shared room with the bed count it was given: Bed A, Bed B, …', function (): void {
    [$owner, $property] = ownerWithRoom();

    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/rooms", roomForm(['room_type' => 'shared', 'beds' => 3]))->assertRedirect();

    expect(spaceLabels(Room::query()->where('name', 'Room 2')->sole()))->toBe(['Bed A', 'Bed B', 'Bed C']);
});

it('ignores a bed count on a private room, and requires one on a shared room', function (): void {
    [$owner, $property] = ownerWithRoom();

    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/rooms", roomForm(['beds' => 5]))->assertSessionHasNoErrors();
    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/rooms", roomForm(['room_type' => 'shared']))->assertSessionHasErrors('beds');

    expect(spaceLabels(Room::query()->where('name', 'Room 2')->sole()))->toBe(['Whole room']);
});

it('validates the room form', function (array $overrides, string $field): void {
    [$owner, $property] = ownerWithRoom();
    $before = Room::query()->count();

    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/rooms", roomForm($overrides))->assertSessionHasErrors($field);

    expect(Room::query()->count())->toBe($before)->and(auditRows())->toBeEmpty();
})->with([
    'no name' => [['name' => ''], 'name'],
    'bad type' => [['room_type' => 'suite'], 'room_type'],
    'rate not pesos' => [['monthly_rate' => '65x'], 'monthly_rate'],
    'rate of zero' => [['monthly_rate' => '0'], 'monthly_rate'],
    'rate with three decimals' => [['monthly_rate' => '6500.123'], 'monthly_rate'],
    'rate beyond the column' => [['monthly_rate' => '42949673'], 'monthly_rate'],
    'a negative fee' => [['reservation_fee' => '-1'], 'reservation_fee'],
    'fee not pesos' => [['reservation_fee' => 'free'], 'reservation_fee'],
    'deposit over 3 months' => [['deposit_months' => 4], 'deposit_months'],
    'advance below 0' => [['advance_months' => -1], 'advance_months'],
    'one bed' => [['room_type' => 'shared', 'beds' => 1], 'beds'],
    'thirteen beds' => [['room_type' => 'shared', 'beds' => 13], 'beds'],
]);

it('accepts a fee of zero and the largest amount', function (): void {
    [$owner, $property] = ownerWithRoom();

    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/rooms", roomForm(['reservation_fee' => '0', 'monthly_rate' => '42,949,672.95']))->assertSessionHasNoErrors();

    expect(Room::query()->where('name', 'Room 2')->sole())->reservation_fee->toBe(0)->monthly_rate->toBe(4_294_967_295);
});

it('edits a room: the 3.2 rate form is part of it, and one audit row lists what changed', function (): void {
    [$owner, , $room] = ownerWithRoom();

    $this->actingAs($owner)->put("/landlord/rooms/{$room->id}", roomForm(['name' => 'Corner Room', 'room_type' => 'private', 'monthly_rate' => '7200', 'reservation_fee' => '1500', 'advance_months' => 1]))
        ->assertRedirect("/landlord/properties/{$room->property_id}");

    expect($room->fresh())->name->toBe('Corner Room')->monthly_rate->toBe(720000)->reservation_fee->toBe(150000);
    $row = auditRows('room.updated')->sole();
    expect(json_decode($row->before, true))->toBe(['name' => 'Room 1', 'monthly_rate' => 650000])
        ->and(json_decode($row->after, true))->toBe(['name' => 'Corner Room', 'monthly_rate' => 720000])
        ->and(auditRows())->toHaveCount(1);
});

it('writes no audit row for a save that changes nothing', function (): void {
    [$owner, , $room] = ownerWithRoom();

    $this->actingAs($owner)->put("/landlord/rooms/{$room->id}", roomForm([
        'name' => 'Room 1', 'monthly_rate' => '6500', 'reservation_fee' => '1500', 'deposit_months' => 1, 'advance_months' => 1,
    ]))->assertRedirect();

    expect(auditRows())->toBeEmpty();
});

it('never touches the fee of a reservation that exists: it is a snapshot, and new ones get the new fee', function (): void {
    [$owner, , $room] = ownerWithRoom(beds: 2);
    $first = reserve(User::factory()->renter()->create(), $room, '2027-04-01');
    $this->app['auth']->forgetGuards();
    expect($first->reservation_fee)->toBe(150000);

    $this->actingAs($owner)->put("/landlord/rooms/{$room->id}", roomForm(['room_type' => 'shared', 'monthly_rate' => '9000', 'reservation_fee' => '2000']))->assertRedirect();

    $this->app['auth']->forgetGuards();
    $second = reserve(User::factory()->renter()->create(), $room, '2027-04-01');
    expect(Reservation::query()->findOrFail($first->id)->reservation_fee)->toBe(150000)
        ->and($second->reservation_fee)->toBe(200000)
        ->and($room->fresh()->reservation_fee)->toBe(200000);
});

describe('changing the room type', function (): void {
    it('is allowed while the room has never had a reservation: private → shared renames the space and adds beds', function (): void {
        [$owner, , $room] = ownerWithRoom();

        $this->actingAs($owner)->put("/landlord/rooms/{$room->id}", roomForm(['name' => 'Room 1', 'room_type' => 'shared', 'beds' => 3, 'monthly_rate' => '6500', 'reservation_fee' => '1500', 'advance_months' => 1]))->assertRedirect();

        expect($room->fresh()->room_type)->toBe(RoomType::Shared)->and(spaceLabels($room))->toBe(['Bed A', 'Bed B', 'Bed C']);
        $row = auditRows('room.updated')->sole();
        expect(json_decode($row->before, true))->toEqual(['room_type' => 'private', 'spaces' => ['Whole room']])
            ->and(json_decode($row->after, true))->toEqual(['room_type' => 'shared', 'spaces' => ['Bed A', 'Bed B', 'Bed C']]);
    });

    it('is allowed while the room has never had a reservation: shared → private keeps one space', function (): void {
        [$owner, , $room] = ownerWithRoom(beds: 4);
        $first = Space::query()->where('room_id', $room->id)->orderBy('id')->firstOrFail();

        $this->actingAs($owner)->put("/landlord/rooms/{$room->id}", roomForm(['name' => 'Room 1', 'room_type' => 'private', 'monthly_rate' => '6500', 'reservation_fee' => '1500', 'advance_months' => 1]))->assertRedirect();

        expect($room->fresh()->room_type)->toBe(RoomType::Private)->and(spaceLabels($room))->toBe(['Whole room'])
            ->and(Space::query()->findOrFail($first->id)->label)->toBe('Whole room');
    });

    it('needs a bed count to become shared', function (): void {
        [$owner, , $room] = ownerWithRoom();

        $this->actingAs($owner)->put("/landlord/rooms/{$room->id}", roomForm(['room_type' => 'shared']))->assertStatus(422);

        expect($room->fresh()->room_type)->toBe(RoomType::Private)->and(spaceLabels($room))->toBe(['Whole room'])->and(auditRows())->toBeEmpty();
    });

    it('is refused once the room has had a reservation, whatever its status, and says why', function (string $state): void {
        [$owner, , $room] = ownerWithRoom();
        $space = onlySpace($room);
        Reservation::factory()->for($space, 'space')->{$state}()->create();

        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json'])->put("/landlord/rooms/{$room->id}", roomForm(['room_type' => 'shared', 'beds' => 2]))
            ->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'once it has had a reservation'));

        expect($room->fresh()->room_type)->toBe(RoomType::Private)->and(spaceLabels($room))->toBe(['Whole room'])->and(auditRows())->toBeEmpty();
    })->with(['held', 'expired', 'declined', 'cancelled', 'ended']);

    it('is locked on the form, but the room can still be edited', function (): void {
        [$owner, , $room] = ownerWithRoom();
        $this->actingAs($owner)->get("/landlord/rooms/{$room->id}/edit")->assertInertia(fn (AssertableInertia $page) => $page->where('type_locked', false));

        $this->app['auth']->forgetGuards();
        Reservation::factory()->for(onlySpace($room), 'space')->expired()->create();

        $this->actingAs($owner)->get("/landlord/rooms/{$room->id}/edit")->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Landlord/Rooms/Form')->where('type_locked', true)->where('room.monthly_rate', 650000));
        $this->actingAs($owner)->put("/landlord/rooms/{$room->id}", roomForm(['name' => 'Same type, new name', 'monthly_rate' => '6500', 'reservation_fee' => '1500', 'advance_months' => 1]))->assertRedirect();
    });
});

describe('spaces', function (): void {
    it('adds the next bed to a shared room: the first free label, with one audit row', function (): void {
        [$owner, , $room] = ownerWithRoom(beds: 2);

        $this->actingAs($owner)->post("/landlord/rooms/{$room->id}/spaces")->assertRedirect("/landlord/properties/{$room->property_id}");

        expect(spaceLabels($room))->toBe(['Bed A', 'Bed B', 'Bed C']);
        expect(auditRows('space.added')->sole())->subject_type->toBe('space')->and(auditRows())->toHaveCount(1);

        Space::query()->where('room_id', $room->id)->where('label', 'Bed B')->update(['label' => 'Window']);
        $this->actingAs($owner)->post("/landlord/rooms/{$room->id}/spaces");
        expect(spaceLabels($room))->toBe(['Bed A', 'Window', 'Bed C', 'Bed B']);
    });

    it('refuses a private room a second space, and a shared room a 13th bed', function (): void {
        [$owner, , $private] = ownerWithRoom();
        $shared = Room::factory()->shared(beds: 12)->for($private->property)->create();

        $this->actingAs($owner)->post("/landlord/rooms/{$private->id}/spaces")->assertStatus(422);
        $this->actingAs($owner)->post("/landlord/rooms/{$shared->id}/spaces")->assertStatus(422);

        expect(spaceLabels($private))->toBe(['Whole room'])->and(spaceLabels($shared))->toHaveCount(12)->and(auditRows())->toBeEmpty();
    });

    it('counts only beds in use towards the 12: an archived bed makes room', function (): void {
        [$owner, , $room] = ownerWithRoom(beds: 12);
        Space::query()->where('room_id', $room->id)->orderBy('id')->firstOrFail()->forceFill(['archived_at' => now()])->save();

        $this->actingAs($owner)->post("/landlord/rooms/{$room->id}/spaces")->assertRedirect();

        expect(spaceLabels($room))->toHaveCount(13)->and(spaceLabels($room)[12])->toBe('Bed M');
    });

    it('renames a space; a reservation keeps pointing at it', function (): void {
        [$owner, , $room] = ownerWithRoom(beds: 2);
        $space = Space::query()->where('room_id', $room->id)->orderBy('id')->firstOrFail();
        $reservation = Reservation::factory()->for($space, 'space')->create();

        $this->actingAs($owner)->patch("/landlord/spaces/{$space->id}", ['label' => '  Window   bed '])->assertRedirect("/landlord/properties/{$room->property_id}");

        expect($space->fresh()->label)->toBe('Window bed')->and($reservation->fresh()->space_id)->toBe($space->id);
        $row = auditRows('space.renamed')->sole();
        expect(json_decode($row->before, true))->toBe(['label' => 'Bed A'])->and(json_decode($row->after, true))->toBe(['label' => 'Window bed']);
    });

    it('refuses a label the room already has, an empty one, and writes no audit row when the label is unchanged', function (): void {
        [$owner, , $room] = ownerWithRoom(beds: 2);
        $bedA = Space::query()->where('room_id', $room->id)->orderBy('id')->firstOrFail();

        $this->actingAs($owner)->patch("/landlord/spaces/{$bedA->id}", ['label' => 'Bed B'])->assertStatus(422);
        $this->actingAs($owner)->patch("/landlord/spaces/{$bedA->id}", ['label' => ''])->assertSessionHasErrors('label');
        $this->actingAs($owner)->patch("/landlord/spaces/{$bedA->id}", ['label' => 'Bed A'])->assertRedirect();

        expect($bedA->fresh()->label)->toBe('Bed A')->and(auditRows())->toBeEmpty();
    });

    it('allows the same label in another room', function (): void {
        [$owner, $property, $room] = ownerWithRoom(beds: 2);
        $other = Room::factory()->shared(beds: 2)->for($property)->create();
        Space::query()->where('room_id', $room->id)->where('label', 'Bed A')->update(['label' => 'Window']);
        $space = Space::query()->where('room_id', $other->id)->where('label', 'Bed B')->firstOrFail();

        $this->actingAs($owner)->patch("/landlord/spaces/{$space->id}", ['label' => 'Window'])->assertRedirect();

        expect($space->fresh()->label)->toBe('Window');
    });
});

it('serves the room forms', function (): void {
    [$owner, $property, $room] = ownerWithRoom(beds: 2);

    $this->actingAs($owner)->get("/landlord/properties/{$property->id}/rooms/create")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Landlord/Rooms/Form')->where('room', null)->where('property.name', 'Alpha Home')->where('type_locked', false));
    $this->actingAs($owner)->get("/landlord/rooms/{$room->id}/edit")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('room.room_type', 'shared')->where('room.bed_count', 2)->where('room.reservation_fee', 150000));
});

it('answers another account\'s room and space with 404 and a caretaker with 403', function (): void {
    [$owner, , $room] = ownerWithRoom(beds: 2);
    [, , $theirs] = ownerWithRoom(beds: 2, name: 'Beta Home');
    $theirSpace = Space::query()->where('room_id', $theirs->id)->firstOrFail();
    $caretaker = landlordUserOf(accountIdOf($owner), LandlordRole::Caretaker);

    $this->actingAs($owner)->put("/landlord/rooms/{$theirs->id}", roomForm())->assertNotFound();
    $this->actingAs($owner)->post("/landlord/rooms/{$theirs->id}/spaces")->assertNotFound();
    $this->actingAs($owner)->patch("/landlord/spaces/{$theirSpace->id}", ['label' => 'Hijacked'])->assertNotFound();
    $this->actingAs($owner)->post("/landlord/properties/{$theirs->property_id}/rooms", roomForm())->assertNotFound();
    $this->actingAs($caretaker)->put("/landlord/rooms/{$room->id}", roomForm())->assertForbidden();
    $this->actingAs($caretaker)->post("/landlord/rooms/{$room->id}/spaces")->assertForbidden();

    expect($theirSpace->fresh()->label)->not->toBe('Hijacked')->and(auditRows())->toBeEmpty();
});
