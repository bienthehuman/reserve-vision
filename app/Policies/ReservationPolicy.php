<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Policies\Concerns\ChecksAccess;
use Illuminate\Auth\Access\Response;

/**
 * docs/ACCESS.md "Permission matrix"; mapped row by row in
 * tests/Feature/Docs/PermissionMatrixTest.php. The abilities say who may act;
 * whether the reservation's status allows it is Reservation::assertCanMoveTo(),
 * checked by each action on the row it has locked.
 */
final class ReservationPolicy
{
    use ChecksAccess;

    /** View reservations for own account: the list. */
    public function viewAny(User $user): Response
    {
        return $this->landlordRole($user, self::MEMBERS);
    }

    /** View reservations for own account: one reservation. */
    public function view(User $user, Reservation $reservation): Response
    {
        return $this->landlordOn($user, $reservation->landlord_account_id, self::MEMBERS);
    }

    /** View own reservations (the renter's "your hold" page). */
    public function viewOwn(User $user, Reservation $reservation): Response
    {
        return $this->renterOwns($user, $reservation->renter_id);
    }

    /** Reserve a space, upload proof, cancel own reservation: reserve in `$room`. */
    public function create(User $user, Room $room): Response
    {
        return $this->renter($user);
    }

    /** Reserve a space, upload proof, cancel own reservation. */
    public function cancel(User $user, Reservation $reservation): Response
    {
        return $this->renterOwns($user, $reservation->renter_id);
    }

    /** Reject proof, confirm or decline. */
    public function rejectProof(User $user, Reservation $reservation): Response
    {
        return $this->landlordOn($user, $reservation->landlord_account_id, self::MEMBERS);
    }

    /** Reject proof, confirm or decline. */
    public function confirm(User $user, Reservation $reservation): Response
    {
        return $this->landlordOn($user, $reservation->landlord_account_id, self::MEMBERS);
    }

    /** Reject proof, confirm or decline. */
    public function decline(User $user, Reservation $reservation): Response
    {
        return $this->landlordOn($user, $reservation->landlord_account_id, self::MEMBERS);
    }

    /** Undo a confirmation. */
    public function undoConfirmation(User $user, Reservation $reservation): Response
    {
        return $this->landlordOn($user, $reservation->landlord_account_id, self::MEMBERS);
    }

    /** Mark moved in, end stay. */
    public function markMovedIn(User $user, Reservation $reservation): Response
    {
        return $this->landlordOn($user, $reservation->landlord_account_id, self::MEMBERS);
    }

    /** Mark moved in, end stay. */
    public function endStay(User $user, Reservation $reservation): Response
    {
        return $this->landlordOn($user, $reservation->landlord_account_id, self::MEMBERS);
    }

    /** Withdraw a confirmed reservation. */
    public function withdraw(User $user, Reservation $reservation): Response
    {
        return $this->landlordOn($user, $reservation->landlord_account_id, self::OWNERS);
    }

    /** Record a refund. */
    public function recordRefund(User $user, Reservation $reservation): Response
    {
        return $this->landlordOn($user, $reservation->landlord_account_id, self::MEMBERS);
    }

    /** See a renter's contact details. */
    public function viewRenterContact(User $user, Reservation $reservation): Response
    {
        return $this->renterContact($user, $reservation->landlord_account_id, $reservation->renter_id);
    }
}
