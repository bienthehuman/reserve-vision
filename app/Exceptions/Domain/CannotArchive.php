<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Models\Reservation;
use Illuminate\Support\Collection;

/** Why a property, room or space can't be archived. The message names what to resolve first. */
final class CannotArchive extends BusinessRuleException
{
    private const int NAMED = 5;

    /**
     * @param  Collection<int, Reservation>  $reservations  the blocking reservations, with `renter` loaded
     */
    public static function blockedBy(string $what, Collection $reservations): self
    {
        $named = $reservations->take(self::NAMED)->map(fn (Reservation $reservation): string => sprintf(
            '#%d (%s, %s to %s, %s)',
            $reservation->id,
            $reservation->renter->full_name,
            $reservation->move_in->format('M j, Y'),
            $reservation->move_out->format('M j, Y'),
            str_replace('_', ' ', $reservation->status->value),
        ))->implode('; ');
        $more = $reservations->count() > self::NAMED ? ' and '.($reservations->count() - self::NAMED).' more' : '';

        return new self("{$what} can't be archived while it has reservations that still hold a space: {$named}{$more}. "
            .'Confirm, decline or cancel them first, or wait until they end.');
    }

    public static function onlySpaceOfPrivateRoom(): self
    {
        return new self('A private room has one space. Archive the room instead.');
    }
}
