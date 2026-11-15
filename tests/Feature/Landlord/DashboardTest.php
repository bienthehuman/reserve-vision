<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// Slice 4.1: the dashboard's four figures, and the lists behind them (`?status=` / `?show=`).
// "Now" is Mar 15, 2027, 10:00 in Manila, so the business date is 2027-03-15.

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/** A reservation on a bed of `$room`, with a renter named `$last`. */
function dashReservation(Room $room, string $last, string $state, array $attributes = []): Reservation
{
    $space = Space::query()->where('room_id', $room->id)->whereNotIn('id', Reservation::query()->select('space_id'))->orderBy('id')->firstOrFail();

    return Reservation::factory()->for($space, 'space')->for(User::factory()->renter()->create(['first_name' => 'Ana', 'last_name' => $last]), 'renter')->{$state}()->create($attributes);
}

/**
 * An account with 12 beds in one published property: 8 + 3 usable ones and an archived bed, plus an
 * archived room, so the denominator is 11.
 *
 * @return array{0: User, 1: Room, 2: Room}
 */
function dashboardWorld(): array
{
    $owner = landlordUser();
    $property = Property::factory()->published()->create(['landlord_account_id' => accountIdOf($owner)]);
    $big = Room::factory()->shared(beds: 8)->for($property)->create();
    $small = Room::factory()->shared(beds: 4)->for($property)->create();
    $small->spaces()->orderByDesc('id')->firstOrFail()->forceFill(['archived_at' => now()])->save(); // 3 usable
    Room::factory()->shared(beds: 3)->for($property)->create(['archived_at' => now()]); // none of its beds count

    return [$owner, $big, $small];
}

it('counts proofs to review and the ones that waited more than 24 hours', function (): void {
    [$owner, $room] = dashboardWorld();
    $old = dashReservation($room, 'Overdue', 'proofUploaded');
    $fresh = dashReservation($room, 'Fresh', 'proofUploaded');
    dashReservation($room, 'Elsewhere', 'confirmed');
    PaymentProof::query()->where('reservation_id', $old->id)->update(['uploaded_at' => now()->subHours(30)]);
    PaymentProof::query()->where('reservation_id', $fresh->id)->update(['uploaded_at' => now()->subHours(23)]);

    $this->actingAs($owner)->get('/landlord')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Landlord/Dashboard')
        ->where('figures.proofs.count', 2)
        ->where('figures.proofs.overdue', 1)
        ->where('figures.proofs.overdue_hours', 24));

    $names = fn (string $query) => collect($this->actingAs($owner)->get("/landlord/reservations?{$query}")->viewData('page')['props']['reservations'])->pluck('renter_name')->sort()->values()->all();
    expect($names('status=proof_uploaded'))->toBe(['Ana Fresh', 'Ana Overdue'])
        ->and($names('show=proofs_overdue'))->toBe(['Ana Overdue']);
});

it('counts live holds that run out within 6 hours', function (): void {
    [$owner, $room] = dashboardWorld();
    dashReservation($room, 'Soon', 'held', ['hold_expires_at' => now()->addHours(3)]);
    dashReservation($room, 'Edge', 'held', ['hold_expires_at' => now()->addHours(6)]);
    dashReservation($room, 'Later', 'held', ['hold_expires_at' => now()->addHours(7)]);
    dashReservation($room, 'Gone', 'expiredHold');

    $this->actingAs($owner)->get('/landlord')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('figures.holds.count', 2)->where('figures.holds.hours', 6));

    $rows = $this->actingAs($owner)->get('/landlord/reservations?show=holds_expiring')->viewData('page')['props']['reservations'];
    expect(collect($rows)->pluck('renter_name')->sort()->values()->all())->toBe(['Ana Edge', 'Ana Soon']);
});

it('counts confirmed move-ins from today to 7 days ahead', function (): void {
    [$owner, $room] = dashboardWorld();
    $day = fn (int $offset): string => CarbonImmutable::parse('2027-03-15')->addDays($offset)->toDateString();
    dashReservation($room, 'Today', 'confirmed', ['move_in' => $day(0), 'move_out' => $day(180)]);
    dashReservation($room, 'Week', 'confirmed', ['move_in' => $day(7), 'move_out' => $day(180)]);
    dashReservation($room, 'Later', 'confirmed', ['move_in' => $day(8), 'move_out' => $day(180)]);
    dashReservation($room, 'Past', 'confirmed', ['move_in' => $day(-1), 'move_out' => $day(180)]);
    dashReservation($room, 'Unconfirmed', 'proofUploaded', ['move_in' => $day(2), 'move_out' => $day(180)]);

    $this->actingAs($owner)->get('/landlord')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('figures.move_ins.count', 2)->where('figures.move_ins.days', 7));

    $rows = $this->actingAs($owner)->get('/landlord/reservations?show=moving_in')->viewData('page')['props']['reservations'];
    expect(collect($rows)->pluck('renter_name')->sort()->values()->all())->toBe(['Ana Today', 'Ana Week']);
});

it('counts occupancy as stays under way today over the spaces that can be rented', function (): void {
    [$owner, $big, $small] = dashboardWorld();
    $day = fn (int $offset): string => CarbonImmutable::parse('2027-03-15')->addDays($offset)->toDateString();
    dashReservation($big, 'Living', 'active', ['move_in' => $day(-30), 'move_out' => $day(150)]);
    dashReservation($big, 'Confirmed', 'confirmed', ['move_in' => $day(-1), 'move_out' => $day(100)]);
    dashReservation($big, 'Leaves', 'active', ['move_in' => $day(-90), 'move_out' => $day(0)]); // move_out is exclusive: gone today
    dashReservation($big, 'Arrives', 'confirmed', ['move_in' => $day(1), 'move_out' => $day(100)]);
    dashReservation($small, 'Small', 'active', ['move_in' => $day(-10), 'move_out' => $day(100)]);

    $this->actingAs($owner)->get('/landlord')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('figures.occupancy.occupied', 3)->where('figures.occupancy.total', 11));
});

it('shows only the current account\'s figures', function (): void {
    [$owner] = dashboardWorld();
    [, $theirRoom] = dashboardWorld();
    dashReservation($theirRoom, 'Theirs', 'proofUploaded');
    dashReservation($theirRoom, 'Theirs Two', 'active', ['move_in' => '2027-03-01', 'move_out' => '2027-09-01']);

    $this->actingAs($owner)->get('/landlord')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('figures.proofs.count', 0)->where('figures.occupancy.occupied', 0)->where('figures.occupancy.total', 11));
});

it('is the same for a caretaker, and keeps the verification banner for an unverified account', function (): void {
    [$owner, $room] = dashboardWorld();
    dashReservation($room, 'Pending', 'proofUploaded');
    $caretaker = landlordUserOf(accountIdOf($owner), LandlordRole::Caretaker);

    $this->actingAs($caretaker)->get('/landlord')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('role', 'caretaker')->where('figures.proofs.count', 1)->where('account.isVerified', true));

    $unverified = landlordUser(verifiedAccount: false);
    $this->actingAs($unverified)->get('/landlord')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('account.isVerified', false)->where('figures.occupancy.total', 0));
});

it('ignores an unknown ?show= value', function (): void {
    [$owner, $room] = dashboardWorld();
    dashReservation($room, 'Any', 'proofUploaded');

    $this->actingAs($owner)->get('/landlord/reservations?show=nonsense')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('filter.show', null)->has('reservations', 1));
});
