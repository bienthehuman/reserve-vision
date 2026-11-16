<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\ManagesSpaces;
use App\Enums\RoomType;
use App\Exceptions\Domain\CannotEditSpace;
use App\Models\Room;
use App\Models\Space;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Adds a bed to a shared room (owner only: SpacePolicy::create, checked by the request).
 * The label is the first free "Bed A", "Bed B", …; at most 12 beds that aren't archived.
 * A private room has exactly one space, so it can't get another. Locks the room row first
 * (ReserveSpace's lock), so a bed appears between two holds, never during one.
 * One audit row, `space.added`.
 */
final class AddSpace
{
    use ManagesSpaces;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotEditSpace */
    public function handle(Room $room): Space
    {
        return DB::transaction(function () use ($room): Space {
            $room = Room::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();

            if ($room->room_type === RoomType::Private) {
                throw CannotEditSpace::privateRoomHasOne();
            }

            $spaces = Space::query()->where('room_id', $room->id)->sharedLock()->get();

            if ($spaces->whereNull('archived_at')->count() >= self::MAX_BEDS) {
                throw CannotEditSpace::limitReached(self::MAX_BEDS);
            }

            $taken = $spaces->pluck('label')->all();
            $index = 0;
            while (in_array(self::bedLabel($index), $taken, true)) {
                $index++;
            }

            $space = Space::create(['room_id' => $room->id, 'landlord_account_id' => $room->landlord_account_id, 'label' => self::bedLabel($index)]);

            $this->audit->record('space.added', $space, [], ['label' => $space->label, 'room_id' => $room->id]);

            return $space;
        });
    }
}
