<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\Domain\CannotArchive;
use App\Models\Room;
use App\Support\AuditLogger;
use App\Support\BlockingReservations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Archives a room (owner only: RoomPolicy::archive, checked by the controller): renters can't
 * see or reserve it any more. Refused while a reservation that holds any of its spaces exists
 * (`Reservation::blocking()`, any dates), naming them. Already archived: nothing happens.
 * Locks the room row first, ReserveSpace's lock (see ArchiveSpace). One audit row, `room.archived`.
 */
final class ArchiveRoom
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotArchive */
    public function handle(Room $room): Room
    {
        return DB::transaction(function () use ($room): Room {
            $room = Room::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();

            if ($room->archived_at !== null) {
                return $room;
            }

            $blocking = BlockingReservations::on(BlockingReservations::spaceIdsOf([$room->id]));

            if ($blocking->isNotEmpty()) {
                throw CannotArchive::blockedBy($room->name, $blocking);
            }

            $room->archived_at = CarbonImmutable::instance(Date::now());
            $room->save();

            $this->audit->record('room.archived', $room, ['archived_at' => null], ['archived_at' => $room->archived_at]);

            return $room;
        });
    }
}
