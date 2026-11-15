<?php

declare(strict_types=1);

use App\Actions\ChangeRoomRate;
use App\Enums\LandlordRole;
use App\Exceptions\Domain\InvalidRate;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;

// Slice 3.2: PATCH /landlord/rooms/{room}/rate, owner only, one `room.rate_changed` audit row.

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/** An owner and a room of their account at ₱6,500. */
function ownerWithRoom(): array
{
    $owner = landlordUser();
    $room = Room::factory()->for(Property::factory()->published()->state(['landlord_account_id' => accountIdOf($owner)]))
        ->create(['monthly_rate' => 650000]);

    return [$owner, $room];
}

it('changes the rate, stores centavos and writes one audit row', function (string $typed, int $centavos): void {
    [$owner, $room] = ownerWithRoom();

    $this->actingAs($owner)->patch("/landlord/rooms/{$room->id}/rate", ['monthly_rate' => $typed])
        ->assertRedirect("/landlord/properties/{$room->property_id}")
        ->assertSessionHas('status');

    expect($room->fresh()->monthly_rate)->toBe($centavos);

    $rows = auditRows('room.rate_changed');
    expect($rows)->toHaveCount(1)
        ->and(auditRows())->toHaveCount(1);
    expect($rows[0])
        ->actor_id->toBe($owner->id)
        ->landlord_account_id->toBe(accountIdOf($owner))
        ->subject_type->toBe('room')
        ->subject_id->toBe($room->id);
    expect(json_decode($rows[0]->before, true))->toBe(['monthly_rate' => 650000])
        ->and(json_decode($rows[0]->after, true))->toBe(['monthly_rate' => $centavos]);
})->with([
    'whole pesos' => ['7500', 750000],
    'with centavos' => ['7500.50', 750050],
    'one decimal' => ['7500.5', 750050],
    'thousands comma' => ['7,500', 750000],
    'the largest rate' => ['42949672.95', 4294967295],
]);

it('rejects a rate that is not a positive amount of pesos', function (string $typed): void {
    [$owner, $room] = ownerWithRoom();

    $this->actingAs($owner)->patch("/landlord/rooms/{$room->id}/rate", ['monthly_rate' => $typed])
        ->assertSessionHasErrors('monthly_rate');

    expect($room->fresh()->monthly_rate)->toBe(650000)
        ->and(auditRows())->toBeEmpty();
})->with(['', '0', '0.00', '-100', 'abc', '12.345', '1e5', '₱6500', '42949673', '99999999999']);

it('writes no audit row when the rate stays the same', function (): void {
    [$owner, $room] = ownerWithRoom();

    $this->actingAs($owner)->patch("/landlord/rooms/{$room->id}/rate", ['monthly_rate' => '6500'])->assertRedirect();

    expect(auditRows())->toBeEmpty();
});

it('guards the action itself against an impossible rate', function (int $centavos): void {
    [, $room] = ownerWithRoom();

    expect(fn () => app(ChangeRoomRate::class)->handle($room, $centavos))->toThrow(InvalidRate::class);
    expect($room->fresh()->monthly_rate)->toBe(650000)->and(auditRows())->toBeEmpty();
})->with([0, -1, 4294967296]);

it('leaves existing reservations with the fee they were made with', function (): void {
    [$owner, $room] = ownerWithRoom();
    $room->forceFill(['reservation_fee' => 150000])->save();
    $renter = User::factory()->renter()->create();
    $reservation = reserve($renter, $room, '2027-04-01');
    $before = $reservation->fresh()->toArray();

    $this->actingAs($owner)->patch("/landlord/rooms/{$room->id}/rate", ['monthly_rate' => '9999'])->assertRedirect();

    expect($room->fresh()->monthly_rate)->toBe(999900)
        ->and($reservation->fresh()->toArray())->toBe($before)
        ->and($reservation->fresh()->reservation_fee)->toBe(150000)
        ->and(Reservation::query()->count())->toBe(1);
});

it('refuses a caretaker with 403 and changes nothing', function (): void {
    [$owner, $room] = ownerWithRoom();
    $caretaker = landlordUserOf(accountIdOf($owner), LandlordRole::Caretaker);

    $this->actingAs($caretaker)->patch("/landlord/rooms/{$room->id}/rate", ['monthly_rate' => '1'])->assertForbidden();

    expect($room->fresh()->monthly_rate)->toBe(650000)->and(auditRows())->toBeEmpty();
});
