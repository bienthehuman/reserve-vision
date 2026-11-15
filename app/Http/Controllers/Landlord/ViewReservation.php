<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Models\PaymentProof;
use App\Models\Reservation;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One reservation of the account. The only landlord page with the renter's
 * email and phone (matrix: "See a renter's contact details"), asked of the
 * policy as its own ability. Payment proofs are listed by amount, reference
 * and status; the file's path is never sent (files come with signed URLs in 5.3).
 * Another account's reservation never gets here: the scope makes the route
 * binding a 404.
 */
final class ViewReservation
{
    public function __invoke(Reservation $reservation): Response
    {
        Gate::authorize('view', $reservation);

        $reservation->load(['space.room.property', 'renter', 'paymentProofs']);
        $space = $reservation->space;
        $room = $space->room;
        $property = $room->property;
        $renter = $reservation->renter;

        return Inertia::render('Landlord/Reservations/Show', [
            'reservation' => [
                'id' => $reservation->id,
                'status' => $reservation->status->value,
                'is_live_hold' => $reservation->isLiveHold(),
                'reservation_fee' => $reservation->reservation_fee,
                'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
                'move_in' => $reservation->move_in->toDateString(),
                'move_out' => $reservation->move_out->toDateString(),
                'created_at' => $reservation->created_at->toIso8601String(),
            ],
            'place' => [
                'property_id' => $property->id,
                'property_name' => $property->name,
                'room_name' => $room->name,
                'space_label' => $space->label,
            ],
            'renter' => [
                'name' => $renter->full_name,
                'email' => Gate::allows('viewRenterContact', $reservation) ? $renter->email : null,
                'phone' => Gate::allows('viewRenterContact', $reservation) ? $renter->phone : null,
            ],
            'proofs' => $reservation->paymentProofs
                ->sortByDesc('uploaded_at')
                ->values()
                ->map(fn (PaymentProof $proof): array => [
                    'id' => $proof->id,
                    'amount' => $proof->amount,
                    'reference_no' => $proof->reference_no,
                    'status' => $proof->status->value,
                    'uploaded_at' => $proof->uploaded_at->toIso8601String(),
                    'rejection_reason' => $proof->rejection_reason,
                ])->all(),
        ]);
    }
}
