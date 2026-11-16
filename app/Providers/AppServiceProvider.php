<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Support\CurrentLandlordAccount;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

final class AppServiceProvider extends ServiceProvider
{
    /** Reserve requests per renter per minute (RESERVATIONS.md "Booking rules"). */
    public const int RESERVATIONS_PER_MINUTE = 10;

    /** Invitation links opened, and accepts tried, per IP per minute (ACCESS.md "Accepting an invitation"). */
    public const int INVITATIONS_PER_MINUTE = 10;

    public function register(): void
    {
        // Scoped, not a singleton: dropped between queue jobs (and requests on a
        // long-lived server), so one landlord's account can never answer for the next.
        $this->app->scoped(CurrentLandlordAccount::class);
    }

    public function boot(): void
    {
        // The queue worker resets scoped instances between jobs but keeps the auth
        // guards, so a job that logged a user in would leave them logged in for the
        // next job, and the isolation scope would follow them. Looping fires before
        // each job in a daemon worker only, never for sync jobs inside a request.
        Queue::looping(function (): void {
            Auth::forgetGuards();
        });

        RateLimiter::for('reservations', fn (Request $request): Limit => Limit::perMinute(self::RESERVATIONS_PER_MINUTE)
            ->by('reservations|'.($request->user() instanceof User ? $request->user()->id : $request->ip()))
            ->response($this->tooManyReservations(...)));

        // Two buckets, so a person who mistypes the sign-up form a few times can still load the page.
        // Guessing a 48-character token at 10 a minute is hopeless; the page answers 429 beyond that.
        RateLimiter::for('invitations', fn (Request $request): Limit => Limit::perMinute(self::INVITATIONS_PER_MINUTE)
            ->by('invitations|'.$request->ip()));
        RateLimiter::for('invitation-accept', fn (Request $request): Limit => Limit::perMinute(self::INVITATIONS_PER_MINUTE)
            ->by('invitation-accept|'.$request->ip())
            ->response(fn (Request $request, array $headers): Response => $this->tooMany(
                $request, $headers, __('Too many tries. Wait a minute, then try again.'),
            )));
    }

    /**
     * Same shape as a broken business rule: JSON gets the message, a form post
     * goes back with it in the `error` flash (a bare 429 would be an error page).
     *
     * @param  array<array-key, mixed>  $headers
     */
    private function tooManyReservations(Request $request, array $headers): Response
    {
        return $this->tooMany($request, $headers, __('You\'ve tried to reserve too many times in a minute. Wait a moment, then try again.'));
    }

    /**
     * @param  array<array-key, mixed>  $headers
     */
    private function tooMany(Request $request, array $headers, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 429, $headers);
        }

        return back(303)->with('error', $message)->withHeaders($headers);
    }
}
