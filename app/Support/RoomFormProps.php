<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;

/**
 * The props of `Landlord/Rooms/Form`. `type_locked` says the room has had a reservation, so
 * its type can't change (UpdateRoom decides again under the room lock). Money goes to the form as centavos.
 */
final class RoomFormProps
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Property $property, ?Room $room): array
    {
        return [
            'property' => ['id' => $property->id, 'name' => $property->name],
            'room' => $room === null ? null : [
                'id' => $room->id,
                'name' => $room->name,
                'room_type' => $room->room_type->value,
                'monthly_rate' => $room->monthly_rate,
                'reservation_fee' => $room->reservation_fee,
                'deposit_months' => $room->deposit_months,
                'advance_months' => $room->advance_months,
                'bed_count' => $room->spaces()->whereNull('spaces.archived_at')->count(),
            ],
            'type_locked' => $room !== null && Reservation::query()
                ->whereHas('space', fn ($space) => $space->where('spaces.room_id', $room->id))
                ->exists(),
        ];
    }
}
