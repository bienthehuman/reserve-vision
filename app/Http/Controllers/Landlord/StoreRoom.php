<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\CreateRoom;
use App\Http\Requests\RoomRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;

/** POST /landlord/properties/{property}/rooms. Owners only; another account's property is a 404. */
final class StoreRoom
{
    public function __invoke(RoomRequest $request, Property $property, CreateRoom $createRoom): RedirectResponse
    {
        $room = $createRoom->handle($property, $request->details());

        return redirect()
            ->route('landlord.properties.show', $property->id)
            ->with('status', __(':room is added.', ['room' => $room->name]));
    }
}
