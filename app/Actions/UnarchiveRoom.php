<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Room;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Brings an archived room back (owner only: RoomPolicy::unarchive, checked by the controller).
 * Not archived: nothing happens. One audit row, `room.unarchived`.
 */
final class UnarchiveRoom
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Room $room): Room
    {
        return DB::transaction(function () use ($room): Room {
            $room = Room::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();

            if ($room->archived_at === null) {
                return $room;
            }

            $before = ['archived_at' => $room->archived_at];
            $room->archived_at = null;
            $room->save();

            $this->audit->record('room.unarchived', $room, $before, ['archived_at' => null]);

            return $room;
        });
    }
}
