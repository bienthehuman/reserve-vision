<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\FailedPasswordResetLinkRequestResponse;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Support\DemoLogins;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify, headless (slice 2.4): Fortify's controllers and login pipeline, our
 * routes (routes/auth.php), Inertia pages (resources/js/Pages/Auth) and actions.
 */
final class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // routes/auth.php lists the routes we use, with their rate limits.
        Fortify::ignoreRoutes();

        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
        // bind, not singleton: Fortify passes the broker status as a constructor argument.
        $this->app->bind(FailedPasswordResetLinkRequestResponseContract::class, FailedPasswordResetLinkRequestResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Password::defaults(fn (): Password => Password::min(8)->max(255));

        $this->configureViews();
        $this->configureRateLimits();
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('Auth/Login', [
            'canResetPassword' => true,
            // Only with APP_DEMO=true; otherwise the prop, and so the panel, is absent from the HTML.
            ...(config('app.demo') === true ? ['demoLogins' => DemoLogins::all()] : []),
        ]));

        Fortify::registerView(fn () => Inertia::render('Auth/Register'));

        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword'));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'token' => $request->route('token'),
            'email' => $request->string('email')->toString(),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('Auth/VerifyEmail', [
            'email' => $request->user()?->email,
        ]));
    }

    /**
     * Login: 5 a minute per email + IP, in Fortify's pipeline (config/fortify.php),
     * with the message in lang/en/auth.php. These two answer like a validation
     * error (back to the form, message on the email field) rather than a 429 page.
     */
    private function configureRateLimits(): void
    {
        RateLimiter::for('registration', fn (Request $request): Limit => Limit::perMinute(3)
            ->by('registration|'.$request->ip())
            ->response($this->tooManyAttempts(...)));

        RateLimiter::for('password-reset-link', fn (Request $request): Limit => Limit::perMinute(3)
            ->by('password-reset-link|'.$request->ip())
            ->response($this->tooManyAttempts(...)));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function tooManyAttempts(Request $request, array $headers): Response
    {
        $seconds = (int) ($headers['Retry-After'] ?? 60);

        return back(303)
            ->withInput($request->except(['password', 'password_confirmation']))
            ->withErrors(['email' => trans('auth.throttle', ['seconds' => $seconds])])
            ->withHeaders($headers);
    }
}
