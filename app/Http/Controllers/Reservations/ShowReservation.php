<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reservations;

use App\Enums\ReservationStatus;
use App\Exceptions\Domain\BusinessRuleException;
use App\Models\LandlordAccount;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Listings\ListingCards;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The renter's hold page, `/reservations/{id}` (slice 2.5, completed in 5.3): the status, the bed,
 * the stay and the fee; while the hold is live, its countdown, the property's payment instructions
 * and the upload form; the landlord account's name and phone (a renter reaches them only after
 * reserving: ACCESS.md "Public pages"); every proof sent; and Cancel where the transition table
 * allows it. Someone else's reservation is a 404, never a 403: the renter's own-reservations scope
 * finds nothing, and ReservationPolicy::viewOwn denies as not found behind it.
 */
final class ShowReservation
{
    public function __invoke(Request $request, int $reservation): Response
    {
        /** @var User $renter (auth + renter middleware) */
        $renter = $request->user();

        $reservation = Reservation::query()->ownedByRenter($renter)->findOrFail($reservation);
        Gate::authorize('viewOwn', $reservation);

        $space = $reservation->space()->firstOrFail();
        $room = $space->room()->firstOrFail();
        $property = $room->property()->firstOrFail();
        $account = LandlordAccount::query()->findOrFail($property->landlord_account_id);
        $proofs = $reservation->paymentProofs()->orderByDesc('uploaded_at')->orderByDesc('id')->get();
        $liveHold = $reservation->isLiveHold();

        return Inertia::render('Reservations/Show', [
            'reservation' => [
                'id' => $reservation->id,
                'status' => $reservation->shownStatus()->value,
                'is_live_hold' => $liveHold,
                'reservation_fee' => $reservation->reservation_fee,
                'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
                'move_in' => $reservation->move_in->toDateString(),
                'move_out' => $reservation->move_out->toDateString(),
                'space_label' => $space->label,
                'cancelled_at' => $reservation->cancelled_at?->toIso8601String(),
                // Slice 6.1: the landlord's side. The reason of a decline or withdrawal, and the refund once recorded.
                'withdrawn' => $reservation->wasWithdrawn(),
                'decision_reason' => in_array($reservation->status, [ReservationStatus::Declined, ReservationStatus::Cancelled], true) ? $reservation->decision_reason : null,
                'decided_at' => $reservation->decided_at?->toIso8601String(),
                'proof_rejections' => $reservation->proof_rejections,
                'fee_refunded' => $reservation->fee_refunded,
                'refund_note' => $reservation->fee_refunded === null ? null : $reservation->refund_note,
                'can_upload' => $liveHold,
                'can_cancel' => Gate::allows('cancel', $reservation) && $this->allows($reservation, ReservationStatus::Cancelled),
            ],
            'room' => ['id' => $room->id, 'name' => $room->name],
            'property' => [
                'name' => $property->name,
                'full_address' => $property->full_address,
                'listing_url' => Property::query()->published()->whereKey($property->id)->exists() ? ListingCards::url($property) : null,
                'house_rules' => $property->house_rules,
                // Only while the hold can still take a proof: afterwards there is nothing to pay.
                'payment_instructions' => $liveHold ? $property->payment_instructions : null,
            ],
            'landlord' => ['name' => $account->name, 'contact_phone' => $account->contact_phone],
            'proofs' => $proofs->map(fn (PaymentProof $proof): array => [
                'id' => $proof->id,
                'amount' => $proof->amount,
                'reference_no' => $proof->reference_no,
                'status' => $proof->status->value,
                'uploaded_at' => $proof->uploaded_at->toIso8601String(),
                'rejection_reason' => $proof->rejection_reason,
                'is_image' => $proof->mime_type->isImage(),
                'url' => route('proofs.show', $proof->id, absolute: false),
            ])->all(),
            'csrf' => csrf_token(),
        ]);
    }

    private function allows(Reservation $reservation, ReservationStatus $to): bool
    {
        try {
            $reservation->assertCanMoveTo($to);

            return true;
        } catch (BusinessRuleException) {
            return false;
        }
    }
}
