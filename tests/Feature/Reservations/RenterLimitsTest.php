<?php

declare(strict_types=1);

use App\Exceptions\Domain\HoldLimitReached;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
    $this->renter = User::factory()->renter()->create();
});

const OVERALL_LIMIT_MESSAGE = 'You already have 2 open reservations, the most you can have at once. Cancel one, or wait for a landlord to decide on one, before reserving again.';
const PROPERTY_LIMIT_MESSAGE = 'You already have an open reservation in this property. Cancel it, or wait for the landlord to decide on it, before reserving another room here.';

/** A reservation of $renter in $room in the given factory state. */
function existingReservation(User $renter, Room $room, string $state): Reservation
{
    return Reservation::factory()->{$state}()->create([
        'renter_id' => $renter->id,
        'space_id' => onlySpace($room)->id,
        'move_in' => '2027-04-01',
        'move_out' => '2027-10-01',
    ]);
}

it('T8: a third open reservation is rejected', function (): void {
    reserve($this->renter, reservableRoom(), '2027-04-01');
    reserve($this->renter, reservableRoom(), '2027-04-01');

    expect(fn () => reserve($this->renter, reservableRoom(), '2027-04-01'))
        ->toThrow(HoldLimitReached::class, OVERALL_LIMIT_MESSAGE)
        ->and(Reservation::query()->where('renter_id', $this->renter->id)->count())->toBe(2);
});

it('T8: a second open reservation in the same property is rejected', function (): void {
    $property = Property::factory()->published()->create();
    reserve($this->renter, reservableRoom($property), '2027-04-01');

    expect(fn () => reserve($this->renter, reservableRoom($property), '2027-05-01'))
        ->toThrow(HoldLimitReached::class, PROPERTY_LIMIT_MESSAGE);
});

it('T8: proof_uploaded counts as open', function (): void {
    existingReservation($this->renter, reservableRoom(), 'proofUploaded');
    existingReservation($this->renter, reservableRoom(), 'proofUploaded');

    expect(fn () => reserve($this->renter, reservableRoom(), '2027-04-01'))
        ->toThrow(HoldLimitReached::class, OVERALL_LIMIT_MESSAGE);
});

it('T8: proof_uploaded in the same property counts as open', function (): void {
    $property = Property::factory()->published()->create();
    existingReservation($this->renter, reservableRoom($property), 'proofUploaded');

    expect(fn () => reserve($this->renter, reservableRoom($property), '2027-04-01'))
        ->toThrow(HoldLimitReached::class, PROPERTY_LIMIT_MESSAGE);
});

it('T8: expired holds, cancelled, declined, confirmed, active and ended reservations are not open', function (string $state): void {
    $property = Property::factory()->published()->create();
    existingReservation($this->renter, reservableRoom($property), $state);
    existingReservation($this->renter, reservableRoom(), $state);
    existingReservation($this->renter, reservableRoom(), $state);

    // Neither limit applies: two more open ones, one of them in $property.
    reserve($this->renter, reservableRoom($property), '2027-04-01');
    reserve($this->renter, reservableRoom(), '2027-04-01');

    expect(Reservation::query()->openForRenter($this->renter)->count())->toBe(2);
})->with(['expiredHold', 'expired', 'cancelled', 'declined', 'confirmed', 'active', 'ended']);

it('T8: gives the property message when both limits apply', function (): void {
    $property = Property::factory()->published()->create();
    reserve($this->renter, reservableRoom($property), '2027-04-01');
    reserve($this->renter, reservableRoom(), '2027-04-01');

    expect(fn () => reserve($this->renter, reservableRoom($property), '2027-04-01'))
        ->toThrow(HoldLimitReached::class, PROPERTY_LIMIT_MESSAGE)
        ->and(fn () => reserve($this->renter, reservableRoom(), '2027-04-01'))
        ->toThrow(HoldLimitReached::class, OVERALL_LIMIT_MESSAGE);
});

it('T8: counts only this renter\'s reservations', function (): void {
    $other = User::factory()->renter()->create();
    $property = Property::factory()->published()->create();
    reserve($other, reservableRoom($property), '2027-04-01');
    reserve($other, reservableRoom(), '2027-04-01');

    expect(reserve($this->renter, reservableRoom($property), '2027-04-01')->renter_id)->toBe($this->renter->id);
});

it('T8: frees the slot when an open hold runs out', function (): void {
    reserve($this->renter, reservableRoom(), '2027-04-01');
    reserve($this->renter, reservableRoom(), '2027-04-01');

    $this->travel(24)->hours();

    expect(reserve($this->renter, reservableRoom(), '2027-04-01')->renter_id)->toBe($this->renter->id);
});
