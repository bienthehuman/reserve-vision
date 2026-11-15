<?php

declare(strict_types=1);

use App\Enums\PaymentProofStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomType;
use App\Models\Amenity;
use App\Models\AuditLog;
use App\Models\LandlordAccount;
use App\Models\LandlordInvitation;
use App\Models\LandlordMember;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Space;
use App\Models\User;
use App\Models\ViewingRequest;
use Carbon\CarbonImmutable;

it('creates every model', function (string $model): void {
    $record = $model::factory()->create();

    expect($record->exists)->toBeTrue();
})->with([
    User::class, LandlordAccount::class, LandlordMember::class, LandlordInvitation::class, Property::class,
    Amenity::class, PropertyPhoto::class, Room::class, Space::class, Reservation::class, PaymentProof::class,
    ViewingRequest::class, AuditLog::class,
]);

it('gives a private room its one space and a shared room one per bed', function (): void {
    $private = Room::factory()->create();
    $shared = Room::factory()->shared(beds: 4)->create();

    expect($private->room_type)->toBe(RoomType::Private)
        ->and($private->spaces)->toHaveCount(1)
        ->and($private->spaces->first()?->label)->toBe('Whole room')
        ->and($shared->room_type)->toBe(RoomType::Shared)
        ->and($shared->spaces->pluck('label')->all())->toBe(['Bed A', 'Bed B', 'Bed C', 'Bed D']);
});

it('derives landlord_account_id from the parent all the way down', function (): void {
    $property = Property::factory()->create();
    $room = Room::factory()->shared(2)->for($property)->create();

    $reservation = Reservation::factory()->proofUploaded()->for($room->spaces->firstOrFail())->create();
    $proof = $reservation->paymentProofs->firstOrFail();

    expect($reservation->landlord_account_id)->toBe($property->landlord_account_id)
        ->and($proof->landlord_account_id)->toBe($property->landlord_account_id)
        ->and(PropertyPhoto::factory()->for($property)->create()->landlord_account_id)->toBe($property->landlord_account_id)
        ->and(ViewingRequest::factory()->for($property)->create()->landlord_account_id)->toBe($property->landlord_account_id);
});

it('publishes a property of a verified account', function (): void {
    $property = Property::factory()->published()->create();

    expect($property->is_published)->toBeTrue()
        ->and($property->landlordAccount->is_verified)->toBeTrue();
});

describe('reservation states', function (): void {
    it('held is a live hold and expiredHold is a lapsed one that is still held', function (): void {
        $live = Reservation::factory()->held()->create();
        $lapsed = Reservation::factory()->expiredHold()->create();

        expect($live->status)->toBe(ReservationStatus::Held)
            ->and($live->hold_expires_at?->isFuture())->toBeTrue()
            ->and($lapsed->status)->toBe(ReservationStatus::Held)
            ->and($lapsed->hold_expires_at?->isPast())->toBeTrue();
    });

    it('proofUploaded has one pending proof for the fee', function (): void {
        $reservation = Reservation::factory()->proofUploaded()->create();
        $proof = $reservation->paymentProofs->firstOrFail();

        expect($reservation->status)->toBe(ReservationStatus::ProofUploaded)
            ->and($reservation->paymentProofs)->toHaveCount(1)
            ->and($proof->status)->toBe(PaymentProofStatus::Pending)
            ->and($proof->amount)->toBe($reservation->reservation_fee);
    });

    it('confirmed is decided by an owner of the same account, with an accepted proof', function (): void {
        $reservation = Reservation::factory()->confirmed()->create();
        $decider = LandlordMember::query()->where('user_id', $reservation->decided_by)->firstOrFail();

        expect($reservation->status)->toBe(ReservationStatus::Confirmed)
            ->and($decider->landlord_account_id)->toBe($reservation->landlord_account_id)
            ->and($reservation->paymentProofs->firstOrFail()->status)->toBe(PaymentProofStatus::Accepted);
    });

    it('active and ended have the dates their status implies', function (): void {
        $active = Reservation::factory()->active()->create();
        $ended = Reservation::factory()->ended()->create();

        expect($active->status)->toBe(ReservationStatus::Active)
            ->and($active->move_in->isPast())->toBeTrue()
            ->and($active->move_out->isFuture())->toBeTrue()
            ->and($ended->status)->toBe(ReservationStatus::Ended)
            ->and($ended->move_out->isPast())->toBeTrue();
    });

    it('creates one in every status the enum has', function (ReservationStatus $status): void {
        $state = match ($status) {
            ReservationStatus::Held => 'held',
            ReservationStatus::ProofUploaded => 'proofUploaded',
            ReservationStatus::Confirmed => 'confirmed',
            ReservationStatus::Active => 'active',
            ReservationStatus::Expired => 'expired',
            ReservationStatus::Declined => 'declined',
            ReservationStatus::Cancelled => 'cancelled',
            ReservationStatus::Ended => 'ended',
        };

        expect(Reservation::factory()->{$state}()->create()->status)->toBe($status);
    })->with(ReservationStatus::cases());

    it('casts lease dates to immutable dates and money to integers', function (): void {
        $reservation = Reservation::factory()->create()->fresh();

        expect($reservation?->move_in)->toBeInstanceOf(CarbonImmutable::class)
            ->and($reservation?->move_in->toTimeString())->toBe('00:00:00')
            ->and($reservation?->reservation_fee)->toBeInt()
            ->and($reservation?->move_in->lt($reservation->move_out))->toBeTrue();
    });
});
