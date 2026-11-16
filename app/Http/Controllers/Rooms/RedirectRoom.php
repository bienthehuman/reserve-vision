<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rooms;

use App\Models\Property;
use App\Models\Room;
use App\Support\Listings\ListingCards;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/rooms/{room}` was the minimal room page (slice 2.5). The room now lives on its listing page:
 * a permanent redirect to its anchor, keeping `?move_in=…&months=…`. Only listed rooms, like before.
 */
final class RedirectRoom
{
    public function __invoke(Request $request, int $room): RedirectResponse
    {
        $room = Room::query()->listed()->findOrFail($room);
        $property = Property::query()->published()->findOrFail($room->property_id);

        $query = array_filter(
            ['move_in' => $request->query('move_in'), 'months' => $request->query('months')],
            is_string(...),
        );

        return redirect()->to(
            ListingCards::url($property).($query === [] ? '' : '?'.http_build_query($query))."#room-{$room->id}",
            301,
        );
    }
}
