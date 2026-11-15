<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\Domain\InvalidRate;
use App\Models\Room;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Changes a room's monthly rate (owner only: RoomPolicy::updateRate, checked by
 * the controller). Reservations keep the fee they were created with
 * (`reservations.reservation_fee` is a snapshot), and the rate itself is never
 * copied onto a reservation, so nothing else changes. One audit row,
 * `room.rate_changed`, with the old and new rate in centavos; none if the rate
 * is the same.
 */
final class ChangeRoomRate
{
    /** `rooms.monthly_rate` is INT UNSIGNED. */
    public const int MAX_CENTAVOS = 4_294_967_295;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  int  $centavos  the new monthly rate, more than 0
     *
     * @throws InvalidRate
     */
    public function handle(Room $room, int $centavos): Room
    {
        if ($centavos < 1 || $centavos > self::MAX_CENTAVOS) {
            throw InvalidRate::make();
        }

        return DB::transaction(function () use ($room, $centavos): Room {
            // Lock and re-read, so the audit row's "before" is what was really replaced.
            $room = Room::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();
            $before = ['monthly_rate' => $room->monthly_rate];

            $room->monthly_rate = $centavos;
            $room->save();

            $this->audit->record('room.rate_changed', $room, $before, ['monthly_rate' => $room->monthly_rate]);

            return $room;
        });
    }
}
