<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * After login: back to the page that asked for it (ACCESS.md, flow B: "the
 * intent is kept across login"), else the user's home: / for renters,
 * /landlord for landlords.
 */
final class LoginResponse implements LoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $redirect = redirect()->intended($user->homePath());

        // A page to come back to with a #fragment (the room on a listing page, slice 5.3): an Inertia visit follows
        // redirects by XHR, which drops fragments, so the browser is sent there with a full page load instead.
        if ($request->hasHeader('X-Inertia') && str_contains($redirect->getTargetUrl(), '#')) {
            return Inertia::location($redirect->getTargetUrl());
        }

        return $redirect;
    }
}
