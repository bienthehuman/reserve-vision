<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\ManagesSpaces;
use App\Data\RoomDetails;
use App\Enums\RoomType;
use App\Exceptions\Domain\CannotEditRoom;
use App\Exceptions\Domain\InvalidRate;
use App\Models\Property;
use App\Models\Room;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Adds a room to a property (owner only: RoomPolicy::create, checked by the request),
 * with its spaces: a private room gets one, "Whole room"; a shared room gets 2 to 12
 * beds, "Bed A", "Bed B", …. One audit row, `room.created`, that lists the spaces.
 */
final class CreateRoom
{
    use ManagesSpaces;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws InvalidRate
     * @throws CannotEditRoom
     */
    public function handle(Property $property, RoomDetails $details): Room
    {
        UpdateRoom::assertMoney($details);

        return DB::transaction(function () use ($property, $details): Room {
            $room = Room::create([
                'property_id' => $property->id,
                'landlord_account_id' => $property->landlord_account_id,
                'name' => $details->name,
                'room_type' => $details->type,
                'monthly_rate' => $details->monthlyRate,
                'deposit_months' => $details->depositMonths,
                'advance_months' => $details->advanceMonths,
                'reservation_fee' => $details->reservationFee,
            ]);

            $spaces = $this->createSpaces($room, $details->type, $details->type === RoomType::Shared ? $details->beds : null);

            $this->audit->record('room.created', $room, [], [...$room->only(UpdateRoom::AUDITED), 'spaces' => $spaces]);

            return $room;
        });
    }
}
