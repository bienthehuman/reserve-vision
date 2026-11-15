<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\UnarchiveRoom;
use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** DELETE /landlord/rooms/{room}/archive. Owners only; another account's record is a 404, and a caretaker gets 403. */
final class UnarchiveRoomController
{
    public function __invoke(Room $room, UnarchiveRoom $action): RedirectResponse
    {
        Gate::authorize('unarchive', $room);

        $model = $action->handle($room);

        return redirect()
            ->route('landlord.properties.show', $model->property_id)
            ->with('status', __(':name is back.', ['name' => $model->name]));
    }
}
