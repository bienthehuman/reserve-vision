<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Models\Property;
use App\Models\Room;
use App\Support\RoomFormProps;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** GET /landlord/properties/{property}/rooms/create. A caretaker gets 403. */
final class NewRoom
{
    public function __invoke(Property $property): Response
    {
        Gate::authorize('create', [Room::class, $property]);

        return Inertia::render('Landlord/Rooms/Form', RoomFormProps::for($property, null));
    }
}
