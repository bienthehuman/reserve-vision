<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reservations;

use App\Actions\UploadPaymentProof;
use App\Http\Requests\StorePaymentProofRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * `POST /reservations/{reservation}/proofs`. The request has found the renter's own reservation
 * (else 404), asked the policy and checked the file by its content; the action decides whether
 * the hold still takes a proof (an expired one is HoldExpired: the `error` flash on the hold page).
 */
final class StorePaymentProof
{
    public function __invoke(StorePaymentProofRequest $request, UploadPaymentProof $upload): RedirectResponse
    {
        /** @var User $renter (auth + renter middleware) */
        $renter = $request->user();
        $reservation = $request->reservation();

        $upload->handle($renter, $reservation, $request->proofFile(), $request->fileType(), $request->amount(), $request->referenceNo());

        return redirect()->route('reservations.show', $reservation->id)
            ->with('status', __('Proof sent. The landlord will check it and confirm your reservation.'));
    }
}
