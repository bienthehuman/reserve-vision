<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\AddSpace;
use App\Models\Room;
use App\Models\Space;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** POST /landlord/rooms/{room}/spaces: one more bed. Owners only. */
final class StoreSpace
{
    public function __invoke(Room $room, AddSpace $addSpace): RedirectResponse
    {
        Gate::authorize('create', [Space::class, $room]);

        $space = $addSpace->handle($room);

        return redirect()
            ->route('landlord.properties.show', $room->property_id)
            ->with('status', __(':bed is added to :room.', ['bed' => $space->label, 'room' => $room->name]));
    }
}
