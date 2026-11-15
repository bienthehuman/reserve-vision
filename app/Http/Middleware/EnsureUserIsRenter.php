<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserType;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Renter-only routes (reserving a room, a renter's own reservations). A landlord user gets 403. */
final class EnsureUserIsRenter
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->type === UserType::Renter, 403);

        /** @var Response */
        return $next($request);
    }
}
