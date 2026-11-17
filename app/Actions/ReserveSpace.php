<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ReservationStatus;
use App\Enums\UserType;
use App\Exceptions\Domain\HoldLimitReached;
use App\Exceptions\Domain\InvalidLeaseLength;
use App\Exceptions\Domain\MoveInOutOfRange;
use App\Exceptions\Domain\NoSpaceAvailable;
use App\Exceptions\Domain\NotARenter;
use App\Exceptions\Domain\RoomNotReservable;
use App\Mail\Reservations\HoldCreated;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Space;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\Mail\Notify;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * A renter reserves a room for `[move_in, move_in + months)`; the server picks
 * the space. Creates a 24-hour `held` reservation. The rules and the lock order
 * are in docs/RESERVATIONS.md ("Booking rules", "Concurrency") and ADR 0001.
 */
final class ReserveSpace
{
    public const int MAX_OPEN = 2;

    public const int MAX_OPEN_PER_PROPERTY = 1;

    public const int MOVE_IN_WINDOW_DAYS = 90;

    public const int MIN_MONTHS = 1;

    public const int MAX_MONTHS = 12;

    public const int HOLD_HOURS = 24;

    /** Attempts in all, so up to two retries after a deadlock or lock wait timeout. */
    public const int ATTEMPTS = 3;

    /**
     * @throws NotARenter
     * @throws InvalidLeaseLength
     * @throws MoveInOutOfRange
     * @throws RoomNotReservable
     * @throws HoldLimitReached
     * @throws NoSpaceAvailable
     */
    public function handle(User $renter, Room $room, CarbonImmutable $moveIn, int $months): Reservation
    {
        // Checks that need nothing from the database, before the transaction.
        if ($renter->type !== UserType::Renter) {
            throw NotARenter::make();
        }

        if ($months < self::MIN_MONTHS || $months > self::MAX_MONTHS) {
            throw InvalidLeaseLength::between(self::MIN_MONTHS, self::MAX_MONTHS);
        }

        $today = BusinessDate::today();
        $lastMoveIn = $today->addDays(self::MOVE_IN_WINDOW_DAYS);
        $moveIn = BusinessDate::of($moveIn);

        if ($moveIn->lt($today) || $moveIn->gt($lastMoveIn)) {
            throw MoveInOutOfRange::between($today, $lastMoveIn);
        }

        $moveOut = $moveIn->addMonthsNoOverflow($months);

        // Only ids go in: the models passed in may be stale, and touching any of
        // their relations inside the transaction would be a non-locking read.
        $reservation = DB::transaction(
            fn (): Reservation => $this->reserve($renter->id, $room->id, $moveIn, $moveOut),
            self::ATTEMPTS,
        );

        // After the transaction (and after commit, however the caller wraps this): the hold email.
        Notify::user($renter, new HoldCreated($reservation->id));

        return $reservation;
    }

    /**
     * THE SNAPSHOT PITFALL, and why every read below is a locking read.
     *
     * Under InnoDB's default REPEATABLE READ, a transaction's read snapshot is
     * fixed by its first *non-locking* read. Say renter B's request runs one
     * plain SELECT (a lazy-loaded `$room->spaces`, a refresh()) and then waits
     * at the room lock while renter A holds it. A inserts the last free bed and
     * commits; B gets the lock, but its plain SELECTs still read the snapshot
     * from before A's insert, see the bed as free, and double-book it. The lock
     * serialized the two requests and still didn't help.
     *
     * Two rules make that impossible by construction:
     *
     * 1. The renter and room locks are the first statements of the transaction,
     *    and nothing is read through the models passed in (only their ids).
     * 2. Every SELECT inside the transaction is a locking read (`FOR UPDATE` or
     *    `LOCK IN SHARE MODE`). InnoDB always serves locking reads from the
     *    latest committed data, never from a snapshot, so no snapshot is ever
     *    used here, even if a caller has already opened a transaction and read
     *    something (the tests do: each one runs inside DatabaseTransactions).
     *
     * Rule 2 instead of running this transaction at READ COMMITTED: MySQL can't
     * change the isolation level inside an open transaction (error 1568), so the
     * guarantee would silently depend on the caller, and READ COMMITTED with
     * statement-based binary logging refuses writes. ADR 0001 has the trade-off
     * (shared next-key locks can make neighbouring rooms deadlock; the retry
     * absorbs it).
     *
     * Keep the checks as separate queries, not one `NOT EXISTS (…)`: a locking
     * clause on the outer SELECT doesn't make a subquery a locking read.
     */
    private function reserve(int $renterId, int $roomId, CarbonImmutable $moveIn, CarbonImmutable $moveOut): Reservation
    {
        // 1. Renter lock: serializes this renter's own requests, so two of them
        //    can't both pass the limits below.
        $renter = User::query()->whereKey($renterId)->lockForUpdate()->firstOrFail();

        // 2. Room lock: serializes every reservation for this room, so renters
        //    queue here instead of racing for its last space. Always renter,
        //    then room, so two requests can't wait on each other in a cycle.
        $room = Room::query()->whereKey($roomId)->lockForUpdate()->firstOrFail();

        if ($room->archived_at !== null || ! $this->propertyIsReservable($room->property_id)) {
            throw RoomNotReservable::make();
        }

        // 3. Renter limits. Property first: it's the more specific message.
        $openInProperties = Reservation::query()
            ->openForRenter($renter)
            ->join('spaces', 'spaces.id', '=', 'reservations.space_id')
            ->join('rooms', 'rooms.id', '=', 'spaces.room_id')
            ->sharedLock()
            ->pluck('rooms.property_id')
            ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0);

        if ($openInProperties->filter(fn (int $id): bool => $id === $room->property_id)->count() >= self::MAX_OPEN_PER_PROPERTY) {
            throw HoldLimitReached::inProperty();
        }

        if ($openInProperties->count() >= self::MAX_OPEN) {
            throw HoldLimitReached::overall(self::MAX_OPEN);
        }

        // 4. The lowest-id non-archived space with no blocking overlapping stay.
        $spaceIds = Space::query()
            ->where('room_id', $room->id)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->sharedLock()
            ->pluck('id');

        $takenSpaceIds = Reservation::query()
            ->whereIn('space_id', $spaceIds)
            ->blocking()
            ->overlapping($moveIn, $moveOut)
            ->sharedLock()
            ->pluck('space_id');

        $spaceId = $spaceIds->diff($takenSpaceIds)->first();

        if (! is_int($spaceId)) {
            throw NoSpaceAvailable::forDates();
        }

        // 5. The hold, with the fee as it is right now (a snapshot: later rate
        //    changes don't touch it).
        return Reservation::create([
            'space_id' => $spaceId,
            'landlord_account_id' => $room->landlord_account_id,
            'renter_id' => $renter->id,
            'move_in' => $moveIn->toDateString(),
            'move_out' => $moveOut->toDateString(),
            'status' => ReservationStatus::Held,
            'reservation_fee' => $room->reservation_fee,
            'hold_expires_at' => CarbonImmutable::instance(Date::now())->addHours(self::HOLD_HOURS),
            'proof_rejections' => 0,
        ]);
    }

    /** Published, not archived, and the landlord account is verified. A locking read, see reserve(). */
    private function propertyIsReservable(int $propertyId): bool
    {
        return Property::query()
            ->join('landlord_accounts', 'landlord_accounts.id', '=', 'properties.landlord_account_id')
            ->where('properties.id', $propertyId)
            ->where('properties.is_published', true)
            ->whereNull('properties.archived_at')
            ->where('landlord_accounts.is_verified', true)
            ->sharedLock()
            ->count() === 1;
    }
}
