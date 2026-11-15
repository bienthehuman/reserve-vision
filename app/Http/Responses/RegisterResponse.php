<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * After sign-up: "check your email". The intended URL stays in the session, so
 * verifying sends the user on to it (VerifyEmailResponse).
 */
final class RegisterResponse implements RegisterResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        return redirect()->route('verification.notice');
    }
}
