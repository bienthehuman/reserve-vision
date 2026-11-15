<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Props shared with every page. Typed in resources/js/types/index.ts.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => fn (): ?array => $this->user($request),
            ],
            'flash' => [
                // A broken business rule on a form post (BusinessRuleException::render()).
                'error' => fn (): mixed => $request->hasSession() ? $request->session()->get('error') : null,
                // A short confirmation: "We've sent a link…", "Your email is verified…".
                'status' => fn (): mixed => $request->hasSession() ? $this->status($request->session()->get('status')) : null,
            ],
        ];
    }

    /**
     * Only what the header needs: never the email, phone or anything else
     * personal, since shared props are in every page's HTML.
     *
     * @return array{id: int, first_name: string, full_name: string, type: string, email_verified: bool}|null
     */
    private function user(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'full_name' => $user->full_name,
            'type' => $user->type->value,
            'email_verified' => $user->hasVerifiedEmail(),
        ];
    }

    /** Fortify flashes a key for "verification link sent"; everything else is already a sentence. */
    private function status(mixed $status): ?string
    {
        if ($status === 'verification-link-sent') {
            $status = __('We\'ve sent a new link. Check your inbox (and the spam folder).');
        }

        return is_string($status) ? $status : null;
    }
}
