<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserType;
use App\Models\Property;
use App\Models\User;
use App\Support\Listings\ListingCards;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On `POST /viewings`, before `auth` and `verified` (ACCESS.md flow C, the 5.3 pattern of KeepReservationIntent): a guest,
 * or a renter who hasn't confirmed their email, is sent on (to log in, or to the "confirm your email" page) with the
 * property's viewing form kept as the page to come back to: `/listings/{city}/{slug}#viewing`. A property that isn't
 * listed (any more) keeps nothing, and the post answers 404 as it would. JSON callers and everyone else pass through.
 */
final class KeepViewingIntent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $unverifiedRenter = $user instanceof User && $user->type === UserType::Renter && ! $user->hasVerifiedEmail();

        if ($request->expectsJson() || ($user !== null && ! $unverifiedRenter)) {
            /** @var Response */
            return $next($request);
        }

        $id = $request->input('property_id');
        $property = is_numeric($id) ? Property::query()->published()->find((int) $id) : null;

        if ($property !== null) {
            $request->session()->put('url.intended', url(ListingCards::url($property)).'#viewing');
        }

        return $unverifiedRenter
            ? redirect()->route('verification.notice')->with('status', __('Confirm your email first, then you can ask for a viewing.'))
            : redirect()->route('login')->with('status', __('Log in or sign up to ask for a viewing. We\'ll bring you back to the property.'));
    }
}
