<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Enums\RoomType;
use App\Exceptions\Domain\CannotEditRoom;
use App\Models\Room;
use App\Models\Space;

/**
 * Creating a room's spaces, shared by CreateRoom and UpdateRoom. Callers hold
 * the room row's lock (or the room is brand new).
 */
trait ManagesSpaces
{
    /** A shared room has 2 to 12 beds (docs/DATA_MODEL.md). */
    public const int MIN_BEDS = 2;

    public const int MAX_BEDS = 12;

    /** A private room's one space. */
    public const string WHOLE_ROOM = 'Whole room';

    /** "Bed A", "Bed B", … for the 1st, 2nd, … bed; past Z, "Bed 27". */
    public static function bedLabel(int $index): string
    {
        return 'Bed '.($index < 26 ? chr(ord('A') + $index) : (string) ($index + 1));
    }

    /** @throws CannotEditRoom */
    private function assertBedCount(?int $beds): int
    {
        if ($beds === null || $beds < self::MIN_BEDS || $beds > self::MAX_BEDS) {
            throw CannotEditRoom::bedsNeeded();
        }

        return $beds;
    }

    /**
     * A private room's "Whole room", or a shared room's beds from the first free label on.
     *
     * @return list<string> the labels created
     */
    private function createSpaces(Room $room, RoomType $type, ?int $beds): array
    {
        $labels = $type === RoomType::Private
            ? [self::WHOLE_ROOM]
            : array_map(self::bedLabel(...), range(0, $this->assertBedCount($beds) - 1));

        foreach ($labels as $label) {
            Space::create(['room_id' => $room->id, 'landlord_account_id' => $room->landlord_account_id, 'label' => $label]);
        }

        return $labels;
    }
}
