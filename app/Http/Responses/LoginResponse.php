<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\Request;
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

        return redirect()->intended($user->homePath());
    }
}
