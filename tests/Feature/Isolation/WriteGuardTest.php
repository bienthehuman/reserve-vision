<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Exceptions\Isolation\CrossAccountWrite;
use App\Models\LandlordAccount;
use App\Models\LandlordMember;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;

// BelongsToLandlordAccount's write guard (docs/ACCESS.md "Isolation"): while a
// landlord user is logged in, new rows get their account, and rows of another
// account can't be written at all.

/** @return array<string, mixed> a property's attributes without its account */
function propertyAttributes(): array
{
    $attributes = Property::factory()->raw();
    unset($attributes['landlord_account_id']);

    return $attributes;
}

it('fills the current account on a new row that has none', function (LandlordRole $role): void {
    $user = landlordUser(role: $role);
    $account = LandlordMember::query()->where('user_id', $user->id)->value('landlord_account_id');

    $this->actingAs($user);
    $property = Property::create(propertyAttributes());

    expect($property->landlord_account_id)->toBe($account);
})->with([LandlordRole::Owner, LandlordRole::Caretaker]);

it('refuses a new row for another account', function (): void {
    $other = LandlordAccount::factory()->create();
    $this->actingAs(landlordUser());

    expect(fn () => Property::create([...propertyAttributes(), 'landlord_account_id' => $other->id]))
        ->toThrow(CrossAccountWrite::class, "Refusing to write Property for landlord account {$other->id}");

    $this->app['auth']->forgetGuards();
    expect(Property::query()->where('landlord_account_id', $other->id)->count())->toBe(0);
});

it('refuses to move a row to another account', function (): void {
    $user = landlordUser();
    $property = Property::factory()->create(['landlord_account_id' => LandlordMember::query()->where('user_id', $user->id)->value('landlord_account_id')]);
    $other = LandlordAccount::factory()->create();

    $this->actingAs($user);

    expect(fn () => $property->update(['landlord_account_id' => $other->id]))->toThrow(CrossAccountWrite::class);
});

it('refuses every write for a landlord user without a membership', function (): void {
    $this->actingAs(User::factory()->landlord()->create());

    expect(fn () => Property::create(propertyAttributes()))
        ->toThrow(CrossAccountWrite::class, 'the current landlord account is none (no membership)');
});

it('stays out of the way for renters: ReserveSpace writes the room\'s account', function (): void {
    $room = reservableRoom();
    $renter = User::factory()->renter()->create();

    $this->actingAs($renter);
    $reservation = reserve($renter, $room, now('Asia/Manila')->addDays(7)->toDateString());

    expect($reservation->landlord_account_id)->toBe($room->landlord_account_id)
        ->and(Reservation::query()->whereKey($reservation->id)->exists())->toBeTrue();
});
