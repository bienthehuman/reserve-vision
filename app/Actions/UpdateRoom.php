<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\ManagesSpaces;
use App\Data\RoomDetails;
use App\Enums\RoomType;
use App\Exceptions\Domain\CannotEditRoom;
use App\Exceptions\Domain\InvalidRate;
use App\Models\Room;
use App\Models\Space;
use App\Support\AuditLogger;
use App\Support\BlockingReservations;
use App\Support\Pesos;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Edits a room: name, type, monthly rate, reservation fee, deposit and advance
 * months (owner only: RoomPolicy::update, checked by the request). It replaces
 * 3.2's ChangeRoomRate: the rate is one field of the room form.
 *
 * Locks the room row FIRST, the same lock as ReserveSpace, so a fee change or a
 * type change queues behind a renter's hold and the other way round, and every
 * read after it is a locking read (see ReserveSpace::reserve()). Reservations
 * keep the fee they were made with: it's a snapshot, nothing here touches them.
 *
 * The type can change only while no reservation has ever pointed at the room's
 * spaces (any status, any date): private → shared renames "Whole room" to
 * "Bed A" and adds beds up to `beds`; shared → private keeps one space, renamed
 * "Whole room", and deletes the others (none was ever reserved).
 * One audit row, `room.updated`, with the changed fields only.
 */
final class UpdateRoom
{
    use ManagesSpaces;

    /** The columns whose changes are audited. */
    public const array AUDITED = ['name', 'room_type', 'monthly_rate', 'reservation_fee', 'deposit_months', 'advance_months'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws InvalidRate */
    public static function assertMoney(RoomDetails $details): void
    {
        if ($details->monthlyRate < 1 || $details->monthlyRate > Pesos::MAX_CENTAVOS) {
            throw InvalidRate::make();
        }

        if ($details->reservationFee < 0 || $details->reservationFee > Pesos::MAX_CENTAVOS) {
            throw InvalidRate::fee();
        }
    }

    /**
     * @throws InvalidRate
     * @throws CannotEditRoom
     */
    public function handle(Room $room, RoomDetails $details): Room
    {
        self::assertMoney($details);

        return DB::transaction(function () use ($room, $details): Room {
            // The room lock comes first. Only the id is used from the model passed in.
            $room = Room::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();

            $before = $room->only(self::AUDITED);
            $spacesBefore = null;
            $spacesAfter = null;

            if ($details->type !== $room->room_type) {
                $spaceIds = BlockingReservations::spaceIdsOf([$room->id]);

                if (BlockingReservations::anyEver($spaceIds)) {
                    throw CannotEditRoom::typeLocked();
                }

                [$spacesBefore, $spacesAfter] = $details->type === RoomType::Shared
                    ? $this->makeShared($room, $details->beds)
                    : $this->makePrivate($room);
            }

            $room->fill([
                'name' => $details->name,
                'room_type' => $details->type,
                'monthly_rate' => $details->monthlyRate,
                'reservation_fee' => $details->reservationFee,
                'deposit_months' => $details->depositMonths,
                'advance_months' => $details->advanceMonths,
            ])->save();

            $this->audit->record(
                'room.updated',
                $room,
                [...$before, ...($spacesBefore === null ? [] : ['spaces' => $spacesBefore])],
                [...$room->only(self::AUDITED), ...($spacesAfter === null ? [] : ['spaces' => $spacesAfter])],
            );

            return $room;
        });
    }

    /**
     * @return array{list<string>, list<string>} the space labels before and after
     */
    private function makeShared(Room $room, ?int $beds): array
    {
        $beds = $this->assertBedCount($beds);
        $spaces = $this->spacesOf($room);
        $before = array_values($spaces->pluck('label')->map(fn (mixed $label): string => is_string($label) ? $label : '')->all());

        $first = $spaces->first();
        $index = 0;

        if ($first instanceof Space) {
            $first->label = self::bedLabel($index++);
            $first->archived_at = null;
            $first->save();
        }

        for (; $index < $beds; $index++) {
            Space::create(['room_id' => $room->id, 'landlord_account_id' => $room->landlord_account_id, 'label' => self::bedLabel($index)]);
        }

        return [$before, array_map(self::bedLabel(...), range(0, $beds - 1))];
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private function makePrivate(Room $room): array
    {
        $spaces = $this->spacesOf($room);
        $before = array_values($spaces->pluck('label')->map(fn (mixed $label): string => is_string($label) ? $label : '')->all());

        $keep = $spaces->first();

        if ($keep instanceof Space) {
            // The others were never reserved (checked above), so nothing points at them.
            Space::query()->where('room_id', $room->id)->whereKeyNot($keep->id)->delete();
            $keep->label = self::WHOLE_ROOM;
            $keep->archived_at = null;
            $keep->save();
        } else {
            Space::create(['room_id' => $room->id, 'landlord_account_id' => $room->landlord_account_id, 'label' => self::WHOLE_ROOM]);
        }

        return [$before, [self::WHOLE_ROOM]];
    }

    /**
     * @return Collection<int, Space>
     */
    private function spacesOf(Room $room): Collection
    {
        return Space::query()->where('room_id', $room->id)->orderBy('id')->lockForUpdate()->get();
    }
}
