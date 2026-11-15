<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentProof;
use App\Models\Reservation;
use App\Models\User;
use App\Policies\Concerns\ChecksAccess;
use Illuminate\Auth\Access\Response;

/** docs/ACCESS.md "Permission matrix" and "Private files"; mapped row by row in tests/Feature/Docs/PermissionMatrixTest.php. */
final class PaymentProofPolicy
{
    use ChecksAccess;

    /** View a payment proof: members of its account, or the renter whose reservation it is. */
    public function view(User $user, PaymentProof $proof): Response
    {
        if ($user->isLandlord()) {
            return $this->landlordOn($user, $proof->landlord_account_id, self::MEMBERS);
        }

        // A renter: the scope never applies to them, so the relation always loads.
        $renterId = Reservation::query()->ownedByRenter($user)->whereKey($proof->reservation_id)->value('renter_id');

        return $this->renterOwns($user, is_numeric($renterId) ? (int) $renterId : 0);
    }

    /** Reserve a space, upload proof, cancel own reservation: a proof for `$reservation`. */
    public function upload(User $user, Reservation $reservation): Response
    {
        return $this->renterOwns($user, $reservation->renter_id);
    }
}
