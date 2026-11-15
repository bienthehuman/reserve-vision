<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\UpdateRoom;
use App\Http\Requests\RoomRequest;
use App\Models\Room;
use Illuminate\Http\RedirectResponse;

/** PUT /landlord/rooms/{room}: name, type, rate, fee, deposit and advance. Owners only. */
final class SaveRoom
{
    public function __invoke(RoomRequest $request, Room $room, UpdateRoom $updateRoom): RedirectResponse
    {
        $room = $updateRoom->handle($room, $request->details());

        return redirect()
            ->route('landlord.properties.show', $room->property_id)
            ->with('status', __('Saved :room. Reservations already made keep the fee they were made with.', ['room' => $room->name]));
    }
}
