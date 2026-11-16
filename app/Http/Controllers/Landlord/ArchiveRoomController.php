<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\ArchiveRoom;
use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** POST /landlord/rooms/{room}/archive. Owners only; another account's record is a 404, and a caretaker gets 403. */
final class ArchiveRoomController
{
    public function __invoke(Room $room, ArchiveRoom $action): RedirectResponse
    {
        Gate::authorize('archive', $room);

        $model = $action->handle($room);

        return redirect()
            ->route('landlord.properties.show', $model->property_id)
            ->with('status', __(':name is archived.', ['name' => $model->name]));
    }
}
