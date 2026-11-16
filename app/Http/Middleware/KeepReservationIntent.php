<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserType;
use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use App\Support\Listings\ListingCards;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On `POST /rooms/{room}/reservations`, before `auth` and `verified` (ACCESS.md, flow B: "the intent
 * is kept across login"). A guest, or a renter who hasn't confirmed their email, is sent on (to log
 * in, or to the "confirm your email" page) with the room and the stay kept as the page to come back
 * to: the room on its listing page, `/listings/{city}/{slug}?move_in=…&months=…#room-{id}`.
 * Laravel's own redirect would only keep the previous page, without the room or the stay. A room
 * that isn't listed (any more) keeps `/rooms/{room}`, which answers 404 as the post itself would.
 * JSON callers and everyone else pass through to the usual middleware.
 */
final class KeepReservationIntent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $unverifiedRenter = $user instanceof User && $user->type === UserType::Renter && ! $user->hasVerifiedEmail();

        if ($request->expectsJson() || ($user !== null && ! $unverifiedRenter)) {
            /** @var Response */
            return $next($request);
        }

        $room = $request->route('room');
        $stay = array_filter([
            'move_in' => $this->valid($request->input('move_in'), '/^\d{4}-\d{2}-\d{2}$/'),
            'months' => $this->valid($request->input('months'), '/^\d{1,2}$/'),
        ], is_string(...));

        $request->session()->put('url.intended', $this->roomUrl(is_numeric($room) ? (int) $room : 0, $stay));

        return $unverifiedRenter
            ? redirect()->route('verification.notice')->with('status', __('Confirm your email first, then you can reserve.'))
            : redirect()->route('login')->with('status', __('Log in or sign up to reserve. We\'ll bring you back to the room.'));
    }

    /** @param  array<string, string>  $stay */
    private function roomUrl(int $roomId, array $stay): string
    {
        $room = Room::query()->listed()->find($roomId);
        $property = $room === null ? null : Property::query()->published()->find($room->property_id);

        if ($room === null || $property === null) {
            return route('rooms.show', ['room' => $roomId, ...$stay]);
        }

        return url(ListingCards::url($property)).($stay === [] ? '' : '?'.http_build_query($stay))."#room-{$room->id}";
    }

    private function valid(mixed $value, string $pattern): ?string
    {
        // A browser posts strings; a test or script may post an int.
        $value = is_int($value) ? (string) $value : $value;

        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
    }
}
