<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Landlord pages are for landlord users (owners and caretakers). A renter gets
 * 403: the page exists, their role can't use it (ACCESS.md, "404, not 403").
 */
final class EnsureUserIsLandlord
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isLandlord() && $user->landlordMember()->exists(), 403);

        /** @var Response */
        return $next($request);
    }
}
