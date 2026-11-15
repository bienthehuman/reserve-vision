<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidLeaseLength;
use App\Exceptions\Domain\MoveInOutOfRange;
use App\Exceptions\Domain\NoSpaceAvailable;
use App\Exceptions\Domain\NotARenter;
use App\Exceptions\Domain\RoomNotReservable;
use App\Models\LandlordAccount;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Space;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
    $this->renter = User::factory()->renter()->create();
});

const MOVE_IN_MESSAGE = 'Choose a move-in date from Mar 15, 2027 to Jun 13, 2027.';

it('creates a 24-hour hold with the dates, the renter and the account', function (): void {
    $room = reservableRoom();

    $hold = reserve($this->renter, $room, '2027-04-01', 6)->fresh();

    expect($hold)->not->toBeNull()
        ->and($hold?->status)->toBe(ReservationStatus::Held)
        ->and($hold?->renter_id)->toBe($this->renter->id)
        ->and($hold?->space_id)->toBe(onlySpace($room)->id)
        ->and($hold?->landlord_account_id)->toBe($room->landlord_account_id)
        ->and($hold?->move_in->toDateString())->toBe('2027-04-01')
        ->and($hold?->move_out->toDateString())->toBe('2027-10-01')
        ->and($hold?->hold_expires_at?->toIso8601String())->toBe(now()->addDay()->toIso8601String())
        ->and($hold?->proof_rejections)->toBe(0);
});

it('accepts a move-in from today to today + 90 days', function (string $moveIn): void {
    expect(reserve($this->renter, reservableRoom(), $moveIn)->move_in->toDateString())->toBe($moveIn);
})->with(['today' => '2027-03-15', 'today + 90' => '2027-06-13']);

it('rejects a move-in before today or after today + 90 days', function (string $moveIn): void {
    expect(fn () => reserve($this->renter, reservableRoom(), $moveIn))
        ->toThrow(MoveInOutOfRange::class, MOVE_IN_MESSAGE);
})->with(['today - 1' => '2027-03-14', 'today + 91' => '2027-06-14']);

it('takes "today" from Manila: a minute before and after midnight there', function (): void {
    $room = reservableRoom();

    // 23:59 Manila on Mar 15 is 15:59 UTC; 00:00 on Mar 16 is 16:00 UTC, the same UTC date.
    $this->travelTo(CarbonImmutable::parse('2027-03-15 23:59', 'Asia/Manila'));
    expect(reserve($this->renter, $room, '2027-03-15')->move_in->toDateString())->toBe('2027-03-15');

    $this->travelTo(CarbonImmutable::parse('2027-03-16 00:00', 'Asia/Manila'));
    expect(fn () => reserve(User::factory()->renter()->create(), reservableRoom(), '2027-03-15'))
        ->toThrow(MoveInOutOfRange::class, 'Choose a move-in date from Mar 16, 2027 to Jun 14, 2027.');
});

it('accepts 1 to 12 months', function (int $months, string $moveOut): void {
    expect(reserve($this->renter, reservableRoom(), '2027-04-01', $months)->move_out->toDateString())->toBe($moveOut);
})->with([[1, '2027-05-01'], [12, '2028-04-01']]);

it('rejects 0 and 13 months', function (int $months): void {
    expect(fn () => reserve($this->renter, reservableRoom(), '2027-04-01', $months))
        ->toThrow(InvalidLeaseLength::class, 'The lease must be from 1 to 12 whole months.');
})->with([0, 13, -1]);

it('moves out on the last day of a shorter month without overflowing', function (string $now, string $moveIn, string $moveOut): void {
    $this->travelTo(CarbonImmutable::parse($now, 'Asia/Manila'));

    expect(reserve($this->renter, reservableRoom(), $moveIn, 1)->move_out->toDateString())->toBe($moveOut);
})->with([
    'Jan 31 + 1 month, 2027' => ['2027-01-10 10:00', '2027-01-31', '2027-02-28'],
    'Jan 31 + 1 month, leap year' => ['2028-01-10 10:00', '2028-01-31', '2028-02-29'],
]);

it('fills a shared room bed by bed in id order, skipping archived beds', function (): void {
    $room = Room::factory()->shared(beds: 4)->for(Property::factory()->published())->create();
    $beds = $room->spaces()->orderBy('id')->get();
    $beds[0]->update(['archived_at' => now()]);

    $taken = collect(range(1, 3))
        ->map(fn () => reserve(User::factory()->renter()->create(), $room, '2027-04-01')->space_id)
        ->all();

    expect($taken)->toBe([$beds[1]->id, $beds[2]->id, $beds[3]->id])
        ->and(fn () => reserve(User::factory()->renter()->create(), $room, '2027-04-01'))
        ->toThrow(NoSpaceAvailable::class);
});

it('gives the lowest-id bed that is free for the dates', function (): void {
    $room = Room::factory()->shared(beds: 3)->for(Property::factory()->published())->create();
    $beds = $room->spaces()->orderBy('id')->get();
    Reservation::factory()->confirmed()->create(['space_id' => $beds[0]->id, 'move_in' => '2027-04-01', 'move_out' => '2027-06-01']);
    Reservation::factory()->expiredHold()->create(['space_id' => $beds[1]->id, 'move_in' => '2027-04-01', 'move_out' => '2027-06-01']);

    expect(reserve($this->renter, $room, '2027-05-01', 1)->space_id)->toBe($beds[1]->id)
        ->and(reserve(User::factory()->renter()->create(), $room, '2027-06-01', 1)->space_id)->toBe($beds[0]->id);
});

it('snapshots the reservation fee', function (): void {
    $room = reservableRoom();
    $room->update(['reservation_fee' => 200000]);

    $hold = reserve($this->renter, $room, '2027-04-01');
    $room->update(['reservation_fee' => 350000]);

    expect($hold->fresh()?->reservation_fee)->toBe(200000);
});

it('reads the fee and archived flag from the database, not from the model passed in', function (): void {
    $room = reservableRoom();
    Room::query()->whereKey($room->id)->update(['reservation_fee' => 99900]);

    expect(reserve($this->renter, $room, '2027-04-01')->reservation_fee)->toBe(99900);

    Room::query()->whereKey($room->id)->update(['archived_at' => now()]);

    expect(fn () => reserve(User::factory()->renter()->create(), $room, '2027-04-01'))->toThrow(RoomNotReservable::class);
});

it('rejects rooms renters can\'t see', function (Room $room): void {
    expect(fn () => reserve($this->renter, $room, '2027-04-01'))
        ->toThrow(RoomNotReservable::class, "This room can't be reserved right now.")
        ->and(Reservation::query()->count())->toBe(0);
})->with([
    'unpublished property' => fn () => Room::factory()->for(Property::factory()->for(LandlordAccount::factory()->verified()))->create(),
    'unverified account' => fn () => tap(reservableRoom(), fn (Room $r) => LandlordAccount::query()->whereKey($r->landlord_account_id)->update(['is_verified' => false])),
    'archived property' => fn () => Room::factory()->for(Property::factory()->published()->archived())->create(),
    'archived room' => fn () => Room::factory()->archived()->for(Property::factory()->published())->create(),
]);

it('rejects a room whose only space is archived', function (): void {
    $room = reservableRoom();
    Space::query()->where('room_id', $room->id)->update(['archived_at' => now()]);

    expect(fn () => reserve($this->renter, $room, '2027-04-01'))->toThrow(NoSpaceAvailable::class);
});

it('rejects a landlord user', function (): void {
    expect(fn () => reserve(User::factory()->landlord()->create(), reservableRoom(), '2027-04-01'))
        ->toThrow(NotARenter::class, 'Only renter accounts can reserve a room.');
});
