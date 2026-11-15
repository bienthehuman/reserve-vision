<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Symfony\Component\HttpFoundation\Response;

/** After clicking the emailed link: the intended page, else the user's home, with a "verified" notice. */
final class VerifyEmailResponse implements VerifyEmailResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return redirect()->intended($user->homePath())
            ->with('status', __('Your email is verified. Welcome to :app!', ['app' => config()->string('app.name')]));
    }
}
