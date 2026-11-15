<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\ChangeRoomRate;
use App\Http\Requests\ChangeRoomRateRequest;
use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** PATCH /landlord/rooms/{room}/rate: owners only. A caretaker gets 403, another account's room a 404. */
final class UpdateRoomRate
{
    public function __invoke(ChangeRoomRateRequest $request, Room $room, ChangeRoomRate $changeRoomRate): RedirectResponse
    {
        Gate::authorize('updateRate', $room);

        $room = $changeRoomRate->handle($room, $request->centavos());

        return redirect()
            ->route('landlord.properties.show', $room->property_id)
            ->with('status', __('The rate of :room is saved.', ['room' => $room->name]));
    }
}
