<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RoomType;
use App\Exceptions\Domain\CannotArchive;
use App\Models\Room;
use App\Models\Space;
use App\Support\AuditLogger;
use App\Support\BlockingReservations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Archives a space (owner only: SpacePolicy::archive, checked by the controller). It can't
 * be rented any more but stays in the history. Refused while a reservation that holds it
 * exists (`Reservation::blocking()`, any dates), naming the reservations to resolve, and for
 * the only space of a private room (archive the room). Already archived: nothing happens.
 *
 * Locks the room row FIRST, ReserveSpace's lock, then checks with locking reads: a renter
 * can't slip a hold onto the space between the check and the archive, and a hold that
 * committed first is seen. One audit row, `space.archived`.
 */
final class ArchiveSpace
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotArchive */
    public function handle(Space $space): Space
    {
        return DB::transaction(function () use ($space): Space {
            $room = Room::query()->whereKey($space->room_id)->lockForUpdate()->firstOrFail();
            $space = Space::query()->whereKey($space->getKey())->lockForUpdate()->firstOrFail();

            if ($space->archived_at !== null) {
                return $space;
            }

            if ($room->room_type === RoomType::Private) {
                throw CannotArchive::onlySpaceOfPrivateRoom();
            }

            $blocking = BlockingReservations::on([$space->id]);

            if ($blocking->isNotEmpty()) {
                throw CannotArchive::blockedBy($space->label, $blocking);
            }

            $space->archived_at = CarbonImmutable::instance(Date::now());
            $space->save();

            $this->audit->record('space.archived', $space, ['archived_at' => null], ['archived_at' => $space->archived_at]);

            return $space;
        });
    }
}
