<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The reservations that still hold a space (`Reservation::blocking()`), for
 * the archive guard. Every read is a locking read, like ReserveSpace's (see the
 * snapshot pitfall there): callers have already locked the room row(s), and a
 * plain SELECT could answer from a snapshot older than a hold that has just committed.
 */
final class BlockingReservations
{
    /**
     * Ids of every space of the rooms, archived ones too: a hold made before a
     * space was archived still blocks it.
     *
     * @param  list<int>  $roomIds
     * @return list<int>
     */
    public static function spaceIdsOf(array $roomIds): array
    {
        return array_values(Space::query()->whereIn('room_id', $roomIds)->orderBy('id')->sharedLock()->pluck('id')
            ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all());
    }

    /**
     * @param  list<int>  $spaceIds
     * @return Collection<int, Reservation> oldest stay first, with `renter` set (for the message)
     */
    public static function on(array $spaceIds): Collection
    {
        if ($spaceIds === []) {
            return new Collection;
        }

        $reservations = Reservation::query()
            ->whereIn('space_id', $spaceIds)
            ->blocking()
            ->orderBy('move_in')
            ->orderBy('id')
            ->sharedLock()
            ->get();

        $renters = User::query()->whereIn('id', $reservations->pluck('renter_id')->all())->sharedLock()->get()->keyBy('id');

        foreach ($reservations as $reservation) {
            $reservation->setRelation('renter', $renters->get($reservation->renter_id));
        }

        return $reservations;
    }

    /**
     * Whether any reservation, of any status and date, ever pointed at these spaces.
     *
     * @param  list<int>  $spaceIds
     */
    public static function anyEver(array $spaceIds): bool
    {
        return $spaceIds !== [] && Reservation::query()->whereIn('space_id', $spaceIds)->sharedLock()->count() > 0;
    }
}
