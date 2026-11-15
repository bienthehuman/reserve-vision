<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use App\Exceptions\Domain\HoldExpired;
use App\Exceptions\Domain\InvalidTransition;
use App\Exceptions\Domain\NoSpaceAvailable;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

it('T5: a hold past its expiry, with no job run, no longer blocks the space', function (): void {
    $room = reservableRoom();
    $first = reserve(User::factory()->renter()->create(), $room, '2027-04-01');

    // Still live a second before the 24 hours are up.
    $this->travel(24 * 3600 - 1)->seconds();
    expect(fn () => reserve(User::factory()->renter()->create(), $room, '2027-04-01'))->toThrow(NoSpaceAvailable::class);

    // At exactly 24 hours `hold_expires_at > now()` is false. No ExpireHolds job has run.
    $this->travel(1)->seconds();
    $second = reserve(User::factory()->renter()->create(), $room, '2027-04-01');

    expect($first->fresh()?->status)->toBe(ReservationStatus::Held)
        ->and($second->space_id)->toBe($first->space_id);
});

it('T5: an expired hold can\'t take a proof or be confirmed', function (): void {
    $hold = reserve(User::factory()->renter()->create(), reservableRoom(), '2027-04-01');

    $hold->assertCanMoveTo(ReservationStatus::ProofUploaded); // live: fine

    $this->travel(25)->hours();
    $hold = $hold->fresh();

    expect($hold?->isLiveHold())->toBeFalse()
        ->and(fn () => $hold?->assertCanMoveTo(ReservationStatus::ProofUploaded))
        ->toThrow(HoldExpired::class, 'This hold has expired, so the space may already be taken. Reserve the room again to get a new hold.')
        ->and(fn () => $hold?->assertCanMoveTo(ReservationStatus::Confirmed))
        ->toThrow(InvalidTransition::class, "A reservation that is held can't become confirmed.");
});

it('lets an expired hold be cancelled or expired, but not expire a live one', function (): void {
    $live = Reservation::factory()->held()->create();
    $expired = Reservation::factory()->expiredHold()->create();

    expect(fn () => $live->assertCanMoveTo(ReservationStatus::Expired))
        ->toThrow(InvalidTransition::class, "This hold hasn't expired yet.")
        ->and(fn () => $expired->assertCanMoveTo(ReservationStatus::Expired))->not->toThrow(Throwable::class)
        ->and(fn () => $expired->assertCanMoveTo(ReservationStatus::Cancelled))->not->toThrow(Throwable::class)
        ->and(fn () => $live->assertCanMoveTo(ReservationStatus::Cancelled))->not->toThrow(Throwable::class);
});

it('knows a live hold from an expired one and from other statuses', function (): void {
    expect(Reservation::factory()->held()->create()->isLiveHold())->toBeTrue()
        ->and(Reservation::factory()->expiredHold()->create()->isLiveHold())->toBeFalse()
        ->and(Reservation::factory()->proofUploaded()->create()->isLiveHold())->toBeFalse()
        ->and(Reservation::factory()->expired()->create()->isLiveHold())->toBeFalse();
});

it('applies the transition table to other statuses unchanged', function (): void {
    $proof = Reservation::factory()->proofUploaded()->create();

    expect(fn () => $proof->assertCanMoveTo(ReservationStatus::Confirmed))->not->toThrow(Throwable::class)
        ->and(fn () => $proof->assertCanMoveTo(ReservationStatus::Active))->toThrow(InvalidTransition::class);
});
