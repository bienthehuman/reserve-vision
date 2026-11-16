<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Models\Room;
use App\Support\RoomFormProps;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** GET /landlord/rooms/{room}/edit. A caretaker gets 403, another account's room a 404. */
final class EditRoom
{
    public function __invoke(Room $room): Response
    {
        Gate::authorize('update', $room);

        return Inertia::render('Landlord/Rooms/Form', RoomFormProps::for($room->property, $room));
    }
}
