<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * "No account with that email" and "a link was sent a moment ago" get the same
 * answer as a sent link, so the form can't be used to find out who has an
 * account. Anything else is shown as an error on the email field.
 */
final class FailedPasswordResetLinkRequestResponse implements FailedPasswordResetLinkRequestResponseContract
{
    public function __construct(private readonly string $status) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if (in_array($this->status, [Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return back()->with('status', trans(Password::RESET_LINK_SENT));
        }

        throw ValidationException::withMessages(['email' => [trans($this->status)]]);
    }
}
