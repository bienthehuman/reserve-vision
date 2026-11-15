<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Exceptions\Domain\NoSpaceAvailable;
use App\Exceptions\Domain\RoomNotReservable;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Space;
use App\Models\User;
use Carbon\CarbonImmutable;

// Slice 4.1: archive and unarchive a space, a room or a property (DATA_MODEL.md "Write actions and their
// correction paths"). Blocked while a blocking reservation exists (RESERVATIONS.md "Vocabulary"), with a
// message that names the reservations.

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/** A reservation on `$space` that is in `$state`, for a renter called `$last`. */
function holdOn(Space $space, string $state, string $last = 'Reyes'): Reservation
{
    return Reservation::factory()->for($space, 'space')->for(User::factory()->renter()->create(['first_name' => 'Ana', 'last_name' => $last]), 'renter')->{$state}()->create();
}

const BLOCKING_STATES = ['held', 'proofUploaded', 'confirmed', 'active'];
const NON_BLOCKING_STATES = ['expiredHold', 'expired', 'declined', 'cancelled', 'ended'];

describe('a space', function (): void {
    it('is archived and brought back, with one audit row each', function (): void {
        [$owner, , $room] = ownerWithRoom(beds: 3);
        $bed = Space::query()->where('room_id', $room->id)->orderBy('id')->firstOrFail();

        $this->actingAs($owner)->post("/landlord/spaces/{$bed->id}/archive")->assertRedirect("/landlord/properties/{$room->property_id}");
        expect($bed->fresh()->archived_at)->not->toBeNull()
            ->and(auditRows('space.archived')->sole()->subject_id)->toBe($bed->id);

        $this->actingAs($owner)->delete("/landlord/spaces/{$bed->id}/archive")->assertRedirect();
        expect($bed->fresh()->archived_at)->toBeNull()
            ->and(auditRows('space.unarchived')->sole()->subject_id)->toBe($bed->id)
            ->and(auditRows())->toHaveCount(2);
    });

    it('is refused while a reservation holds it, now or in the future, and the message names the reservation', function (string $state): void {
        [$owner, , $room] = ownerWithRoom(beds: 3);
        $bed = Space::query()->where('room_id', $room->id)->orderBy('id')->firstOrFail();
        $reservation = holdOn($bed, $state, 'Dela Cruz');

        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json'])->post("/landlord/spaces/{$bed->id}/archive")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, "#{$reservation->id}") && str_contains($m, 'Ana Dela Cruz') && str_contains($m, 'Bed A'));

        expect($bed->fresh()->archived_at)->toBeNull()->and(auditRows())->toBeEmpty();
    })->with(BLOCKING_STATES);

    it('is allowed when the only reservations are over: expired hold, expired, declined, cancelled, ended', function (string $state): void {
        [$owner, , $room] = ownerWithRoom(beds: 3);
        $bed = Space::query()->where('room_id', $room->id)->orderBy('id')->firstOrFail();
        holdOn($bed, $state);

        $this->actingAs($owner)->post("/landlord/spaces/{$bed->id}/archive")->assertRedirect();

        expect($bed->fresh()->archived_at)->not->toBeNull();
    })->with(NON_BLOCKING_STATES);

    it('is not blocked by a reservation on another bed', function (): void {
        [$owner, , $room] = ownerWithRoom(beds: 3);
        [$bedA, $bedB] = Space::query()->where('room_id', $room->id)->orderBy('id')->get()->all();
        holdOn($bedB, 'confirmed');

        $this->actingAs($owner)->post("/landlord/spaces/{$bedA->id}/archive")->assertRedirect();

        expect($bedA->fresh()->archived_at)->not->toBeNull();
    });

    it('names at most five reservations and counts the rest', function (): void {
        [$owner, , $room] = ownerWithRoom(beds: 2);
        $bed = Space::query()->where('room_id', $room->id)->orderBy('id')->firstOrFail();
        foreach (range(1, 7) as $n) {
            Reservation::factory()->for($bed, 'space')->confirmed()->create(['move_in' => "2027-0{$n}-01", 'move_out' => "2027-0{$n}-20"]);
        }

        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json'])->post("/landlord/spaces/{$bed->id}/archive")
            ->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'and 2 more'));
    });

    it('is refused for the one space of a private room: archive the room instead', function (): void {
        [$owner, , $room] = ownerWithRoom();

        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json'])->post('/landlord/spaces/'.onlySpace($room)->id.'/archive')
            ->assertStatus(422)->assertJsonPath('message', 'A private room has one space. Archive the room instead.');

        expect(onlySpace($room)->fresh()->archived_at)->toBeNull();
    });

    it('does nothing, and writes no audit row, when it is already in the state asked for', function (): void {
        [$owner, , $room] = ownerWithRoom(beds: 2);
        $bed = Space::query()->where('room_id', $room->id)->orderBy('id')->firstOrFail();

        $this->actingAs($owner)->delete("/landlord/spaces/{$bed->id}/archive")->assertRedirect();
        $this->actingAs($owner)->post("/landlord/spaces/{$bed->id}/archive");
        $this->actingAs($owner)->post("/landlord/spaces/{$bed->id}/archive")->assertRedirect();

        expect(auditRows())->toHaveCount(1);
    });
});

describe('a room', function (): void {
    it('is archived and brought back, and renters can\'t reserve it meanwhile', function (): void {
        [$owner, , $room] = ownerWithRoom(beds: 2);
        $renter = User::factory()->renter()->create();

        $this->actingAs($owner)->post("/landlord/rooms/{$room->id}/archive")->assertRedirect();
        expect($room->fresh()->archived_at)->not->toBeNull()->and(auditRows('room.archived')->sole()->subject_id)->toBe($room->id);

        $this->app['auth']->forgetGuards();
        expect(fn () => reserve($renter, $room, '2027-04-01'))->toThrow(RoomNotReservable::class);

        $this->actingAs($owner)->delete("/landlord/rooms/{$room->id}/archive")->assertRedirect();
        $this->app['auth']->forgetGuards();
        expect($room->fresh()->archived_at)->toBeNull()->and(reserve($renter, $room, '2027-04-01')->status->value)->toBe('held')
            ->and(auditRows('room.unarchived'))->toHaveCount(1);
    });

    it('is refused while any of its spaces is held, even an archived one, naming the reservations', function (string $state): void {
        [$owner, , $room] = ownerWithRoom(beds: 3);
        $beds = Space::query()->where('room_id', $room->id)->orderBy('id')->get();
        $reservation = holdOn($beds[2], $state, 'Santos');
        $beds[2]->forceFill(['archived_at' => now()])->save(); // a hold made before the bed was archived still blocks

        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json'])->post("/landlord/rooms/{$room->id}/archive")
            ->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, "#{$reservation->id}") && str_contains($m, 'Room 1'));

        expect($room->fresh()->archived_at)->toBeNull()->and(auditRows())->toBeEmpty();
    })->with(BLOCKING_STATES);

    it('is allowed when its reservations are over', function (string $state): void {
        [$owner, , $room] = ownerWithRoom(beds: 3);
        holdOn(Space::query()->where('room_id', $room->id)->firstOrFail(), $state);

        $this->actingAs($owner)->post("/landlord/rooms/{$room->id}/archive")->assertRedirect();

        expect($room->fresh()->archived_at)->not->toBeNull();
    })->with(NON_BLOCKING_STATES);

    it('is refused for a live hold, and allowed the moment the hold runs out, without any job running', function (): void {
        [$owner, , $room] = ownerWithRoom();
        holdOn(onlySpace($room), 'held');

        $this->actingAs($owner)->post("/landlord/rooms/{$room->id}/archive")->assertStatus(422);

        $this->travelTo(now()->addHours(25));
        $this->actingAs($owner)->post("/landlord/rooms/{$room->id}/archive")->assertRedirect();
        expect($room->fresh()->archived_at)->not->toBeNull();
    });

    it('is not blocked by a reservation in another room', function (): void {
        [$owner, $property, $room] = ownerWithRoom();
        $other = Room::factory()->for($property)->create();
        holdOn(onlySpace($other), 'confirmed');

        $this->actingAs($owner)->post("/landlord/rooms/{$room->id}/archive")->assertRedirect();

        expect($room->fresh()->archived_at)->not->toBeNull()->and($other->fresh()->archived_at)->toBeNull();
    });
});

describe('a property', function (): void {
    it('is archived, which also unpublishes it, in one audit row', function (): void {
        [$owner, $property] = ownerWithRoom();
        expect($property->is_published)->toBeTrue();

        $this->actingAs($owner)->post("/landlord/properties/{$property->id}/archive")->assertRedirect("/landlord/properties/{$property->id}");

        $property->refresh();
        expect($property->archived_at)->not->toBeNull()->and($property->is_published)->toBeFalse()->and($property->hasBeenPublished())->toBeTrue();
        $row = auditRows()->sole();
        expect($row->action)->toBe('property.archived')
            ->and(json_decode($row->before, true))->toEqual(['archived_at' => null, 'is_published' => true])
            ->and(json_decode($row->after, true)['is_published'])->toBeFalse();
    });

    it('comes back as a draft: unarchiving doesn\'t publish', function (): void {
        [$owner, $property] = ownerWithRoom();
        $this->actingAs($owner)->post("/landlord/properties/{$property->id}/archive");

        $this->actingAs($owner)->delete("/landlord/properties/{$property->id}/archive")->assertRedirect();

        expect($property->fresh())->archived_at->toBeNull()->is_published->toBeFalse()
            ->and(auditRows('property.unarchived'))->toHaveCount(1);
    });

    it('is refused while a reservation holds a space in any of its rooms, naming it', function (string $state): void {
        [$owner, $property] = ownerWithRoom();
        $second = Room::factory()->shared(beds: 2)->for($property)->create(['name' => 'Second']);
        $reservation = holdOn(Space::query()->where('room_id', $second->id)->orderBy('id')->firstOrFail(), $state, 'Lim');

        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json'])->post("/landlord/properties/{$property->id}/archive")
            ->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, "#{$reservation->id}") && str_contains($m, 'Alpha Home') && str_contains($m, 'Ana Lim'));

        expect($property->fresh())->archived_at->toBeNull()->is_published->toBeTrue()->and(auditRows())->toBeEmpty();
    })->with(BLOCKING_STATES);

    it('is allowed when every reservation is over, and renters then get a 404 for its rooms', function (): void {
        [$owner, $property, $room] = ownerWithRoom();
        holdOn(onlySpace($room), 'ended');

        $this->actingAs($owner)->post("/landlord/properties/{$property->id}/archive")->assertRedirect();

        $this->app['auth']->forgetGuards();
        $this->get("/rooms/{$room->id}")->assertNotFound();
    });
});

it('answers another account\'s records with 404 and a caretaker with 403, changing nothing', function (): void {
    [$owner, $property, $room] = ownerWithRoom(beds: 2);
    [, $theirProperty, $theirRoom] = ownerWithRoom(beds: 2, name: 'Beta Home');
    $theirSpace = Space::query()->where('room_id', $theirRoom->id)->firstOrFail();
    $caretaker = landlordUserOf(accountIdOf($owner), LandlordRole::Caretaker);

    $this->actingAs($owner)->post("/landlord/properties/{$theirProperty->id}/archive")->assertNotFound();
    $this->actingAs($owner)->post("/landlord/rooms/{$theirRoom->id}/archive")->assertNotFound();
    $this->actingAs($owner)->post("/landlord/spaces/{$theirSpace->id}/archive")->assertNotFound();
    $this->actingAs($owner)->delete("/landlord/spaces/{$theirSpace->id}/archive")->assertNotFound();
    $this->actingAs($caretaker)->post("/landlord/properties/{$property->id}/archive")->assertForbidden();
    $this->actingAs($caretaker)->post("/landlord/rooms/{$room->id}/archive")->assertForbidden();
    $this->actingAs($caretaker)->delete("/landlord/rooms/{$room->id}/archive")->assertForbidden();

    expect(Property::query()->withoutGlobalScopes()->whereNotNull('archived_at')->count())->toBe(0)
        ->and(Room::query()->withoutGlobalScopes()->whereNotNull('archived_at')->count())->toBe(0)
        ->and(auditRows())->toBeEmpty();
});

it('never leaves a reservation without a bed: once a bed is archived, ReserveSpace can\'t take it', function (): void {
    [$owner, , $room] = ownerWithRoom(beds: 1 + 1);
    [$bedA, $bedB] = Space::query()->where('room_id', $room->id)->orderBy('id')->get()->all();
    $this->actingAs($owner)->post("/landlord/spaces/{$bedA->id}/archive");
    $this->actingAs($owner)->post("/landlord/spaces/{$bedB->id}/archive");
    $this->app['auth']->forgetGuards();

    expect(fn () => reserve(User::factory()->renter()->create(), $room, '2027-04-01'))->toThrow(NoSpaceAvailable::class);
});
