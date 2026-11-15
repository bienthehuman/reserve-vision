<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

/*
| Laravel Fortify, headless (slice 2.4). Fortify supplies the controllers, the
| login pipeline and the password broker; the pages are Inertia pages
| (App\Providers\FortifyServiceProvider) and the routes are ours (routes/auth.php),
| so only the features below exist and each POST has its rate limit.
| CLAUDE.md, "Auth (slice 2.4)".
*/
return [

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    // "Owner@Example.com" and "owner@example.com" are one login.
    'lowercase_usernames' => true,

    // Unused: App\Http\Responses\LoginResponse sends renters to / and landlords to /landlord.
    'home' => '/',

    'prefix' => '',

    'domain' => null,

    'middleware' => ['web'],

    /*
    | Null on purpose: without a named limiter, Fortify's login pipeline runs
    | EnsureLoginIsNotThrottled (5 tries a minute per email + IP), which answers
    | with a validation error on the email field instead of a bare 429 page.
    | Registration and reset-link limits are in FortifyServiceProvider.
    */
    'limiters' => [
        'login' => null,
    ],

    'views' => true,

    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        // No 2FA, passkeys or profile pages yet (BUILD_PLAN backlog).
    ],

];
