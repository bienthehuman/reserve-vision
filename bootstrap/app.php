<?php

declare(strict_types=1);

use App\Exceptions\Domain\BusinessRuleException;
use App\Http\Middleware\EnsureUserIsLandlord;
use App\Http\Middleware\EnsureUserIsRenter;
use App\Http\Middleware\ExposeRequestTiming;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\KeepReservationIntent;
use App\Http\Middleware\KeepViewingIntent;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Public JSON without the `web` group, so no session cookie stops a cache (slice 4.4).
        then: function (): void {
            Route::middleware(['throttle:places', SubstituteBindings::class])->group(__DIR__.'/../routes/places.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Production runs behind Caddy (compose.prod.yaml), Codespaces behind GitHub's
        // port forwarding. The app port is reachable only from those proxies (it is
        // never published in production), so the X-Forwarded-* headers are trusted.
        $middleware->trustProxies(at: '*');

        // Global, so error pages and /up get the headers too.
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // The concurrency suite's timing probe (a no-op unless CONCURRENCY_PROBE, never in production) wraps
        // the whole route, auth included: a racer refused by `landlord` or a policy still gets the headers.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ExposeRequestTiming::class);

        // A guest's (or an unverified renter's) reserve post keeps the room and the stay as the page to come
        // back to (slice 5.3), so this must run before `auth` and `verified` would redirect on their own.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, KeepReservationIntent::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, KeepViewingIntent::class); // slice 6.2, the same for a viewing request

        $middleware->alias([
            'landlord' => EnsureUserIsLandlord::class,
            'renter' => EnsureUserIsRenter::class,
        ]);

        // Guests on a members-only page go to /login (the URL is kept as "intended");
        // logged-in users on /login or /register go to their home.
        $middleware->redirectGuestsTo(fn (): string => route('login'));
        $middleware->redirectUsersTo(fn (Request $request): string => $request->user() instanceof User
            ? $request->user()->homePath()
            : route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Expected outcomes with a user-facing message (BusinessRuleException::render()).
        $exceptions->dontReport(BusinessRuleException::class);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
