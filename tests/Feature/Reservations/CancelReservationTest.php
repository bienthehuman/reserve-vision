<?php

declare(strict_types=1);

use App\Actions\CancelReservation;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    fakeMediaDisks();
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/** A reservation of `$state` (a factory state) for a new renter, moving in on `$moveIn`. */
function reservationToCancel(string $state, string $moveIn = '2027-04-01'): Reservation
{
    return Reservation::factory()->{$state}()->for(User::factory()->renter()->create(), 'renter')
        ->create(['move_in' => $moveIn, 'move_out' => CarbonImmutable::parse($moveIn)->addMonths(6)->toDateString()]);
}

it('cancels from held (live or expired), proof_uploaded, and confirmed before move-in', function (string $state): void {
    $reservation = reservationToCancel($state);

    $this->actingAs($reservation->renter)->from("/reservations/{$reservation->id}")
        ->post("/reservations/{$reservation->id}/cancel")
        ->assertRedirect("/reservations/{$reservation->id}")
        ->assertSessionHas('status', 'Your reservation is cancelled.');

    $fresh = $reservation->fresh();
    expect($fresh?->status)->toBe(ReservationStatus::Cancelled)
        ->and($fresh?->cancelled_by)->toBe($reservation->renter_id)
        ->and($fresh?->cancelled_at?->equalTo(now()))->toBeTrue()
        ->and(auditRows())->toBeEmpty();
})->with(['held', 'expiredHold', 'proofUploaded', 'confirmed']);

it('frees the space at once: someone else can reserve the same dates', function (): void {
    $room = Room::factory()->for(Property::factory()->published())->create();
    $first = reserve(User::factory()->renter()->create(), $room, '2027-04-01');

    app(CancelReservation::class)->handle($first->renter, $first);

    $second = reserve(User::factory()->renter()->create(), $room, '2027-04-01');
    expect($second->space_id)->toBe($first->space_id);
});

it('refuses a confirmed stay from its move-in date on, and anything final', function (string $state, string $moveIn, string $message): void {
    $reservation = reservationToCancel($state, $moveIn);

    $this->actingAs($reservation->renter)->from("/reservations/{$reservation->id}")
        ->post("/reservations/{$reservation->id}/cancel")
        ->assertRedirect("/reservations/{$reservation->id}")
        ->assertSessionHas('error', $message);

    expect($reservation->fresh()?->status)->toBe($reservation->status);
})->with([
    'confirmed, moving in today' => ['confirmed', '2027-03-15', "This stay has already started, so it can't be cancelled here. Talk to the landlord."],
    'active' => ['active', '2027-03-01', "A reservation that is active can't become cancelled."],
    'already cancelled' => ['cancelled', '2027-04-01', "A reservation that is cancelled can't become cancelled."],
    'declined' => ['declined', '2027-04-01', "A reservation that is declined can't become cancelled."],
]);

it('answers 404 for another renter\'s reservation and 403 for a landlord', function (): void {
    $reservation = reservationToCancel('held');

    $this->actingAs(User::factory()->renter()->create())->post("/reservations/{$reservation->id}/cancel")->assertNotFound();
    $this->actingAs(landlordUser())->post("/reservations/{$reservation->id}/cancel")->assertForbidden();

    expect($reservation->fresh()?->status)->toBe(ReservationStatus::Held);
});

it('locks the reservation row first', function (): void {
    $reservation = reservationToCancel('held');
    $renter = $reservation->renter;
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    app(CancelReservation::class)->handle($renter, $reservation);

    expect($statements[0])->toContain('from `reservations`')->toContain('for update');
});

it('guards confirmed → cancelled by the move-in date in assertCanMoveTo, for the owner\'s withdraw (6.1) too', function (): void {
    expect(fn () => reservationToCancel('confirmed', '2027-03-16')->assertCanMoveTo(ReservationStatus::Cancelled))->not->toThrow(Throwable::class)
        ->and(fn () => reservationToCancel('confirmed', '2027-03-15')->assertCanMoveTo(ReservationStatus::Cancelled))->toThrow(InvalidTransition::class);
});
