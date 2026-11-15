<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use App\Exceptions\Domain\NoSpaceAvailable;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

it('T3: a lease ending Jun 1 and the next starting Jun 1 are both allowed on a single-space room', function (): void {
    $room = reservableRoom();
    Reservation::factory()->confirmed()->create([
        'space_id' => onlySpace($room)->id, 'move_in' => '2027-03-01', 'move_out' => '2027-06-01',
    ]);

    $next = reserve(User::factory()->renter()->create(), $room, '2027-06-01', 3);

    expect($next->status)->toBe(ReservationStatus::Held)
        ->and($next->space_id)->toBe(onlySpace($room)->id)
        ->and($next->move_in->toDateString())->toBe('2027-06-01');
});

it('T3: a lease ending on the day the next one starts is allowed when booked second', function (): void {
    $room = reservableRoom();
    Reservation::factory()->confirmed()->create([
        'space_id' => onlySpace($room)->id, 'move_in' => '2027-06-01', 'move_out' => '2027-12-01',
    ]);

    $before = reserve(User::factory()->renter()->create(), $room, '2027-04-01', 2);

    expect($before->move_out->toDateString())->toBe('2027-06-01');
});

it('T4: a one-day overlap at the end of an existing stay is rejected', function (): void {
    $room = reservableRoom();
    Reservation::factory()->confirmed()->create([
        'space_id' => onlySpace($room)->id, 'move_in' => '2027-04-01', 'move_out' => '2027-06-01',
    ]);

    // Moving in May 31 shares the night of May 31 with the existing stay.
    expect(fn () => reserve(User::factory()->renter()->create(), $room, '2027-05-31', 1))
        ->toThrow(NoSpaceAvailable::class, 'This room has no free space for those dates. Try other dates or another room.')
        ->and(Reservation::query()->count())->toBe(1);
});

it('T4: a one-day overlap at the start of an existing stay is rejected', function (): void {
    $room = reservableRoom();
    Reservation::factory()->confirmed()->create([
        'space_id' => onlySpace($room)->id, 'move_in' => '2027-04-16', 'move_out' => '2027-06-01',
    ]);

    // Mar 17 + 1 month = Apr 17 (exclusive): shares only the night of Apr 16.
    expect(fn () => reserve(User::factory()->renter()->create(), $room, '2027-03-17', 1))
        ->toThrow(NoSpaceAvailable::class);

    // A day earlier it ends as the other begins, which T3 allows.
    expect(reserve(User::factory()->renter()->create(), $room, '2027-03-16', 1)->move_out->toDateString())->toBe('2027-04-16');
});
