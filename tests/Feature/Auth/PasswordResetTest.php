<?php

declare(strict_types=1);

use App\Mail\Auth\ResetPasswordMail;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;

const SENT = 'If there is an account for that email, we\'ve sent it a link to reset the password.';

beforeEach(fn () => Notification::fake());

it('server-renders the forgot-password form', function (): void {
    $response = $this->get('/forgot-password');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/ForgotPassword'));
    expect($response->getContent())->toMatch('/<h1[^>]*>Reset your password<\/h1>/');
});

it('emails a reset link to an existing account', function (): void {
    $user = User::factory()->create();

    $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
        ->assertRedirect('/forgot-password')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', SENT);

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

it('answers an unknown email exactly like a known one, so accounts cannot be found out', function (): void {
    $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.com'])
        ->assertRedirect('/forgot-password')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', SENT);

    Notification::assertNothingSent();
});

it('answers a second request within a minute the same way, without a second mail', function (): void {
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);
    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', SENT);

    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
});

it('server-renders the reset form with the token and email', function (): void {
    $response = $this->get('/reset-password/some-token?email=maria@example.com');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Auth/ResetPassword')
        ->where('token', 'some-token')
        ->where('email', 'maria@example.com'));
});

it('resets the password with a valid token', function (): void {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a brand new password',
        'password_confirmation' => 'a brand new password',
    ])->assertRedirect('/login')->assertSessionHas('status', 'Your password is changed. Log in with the new one.');

    expect(Hash::check('a brand new password', $user->fresh()?->password ?? ''))->toBeTrue();

    // The token works once.
    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'another new password',
        'password_confirmation' => 'another new password',
    ])->assertSessionHasErrors('email');
});

it('rejects a wrong token and a weak password', function (): void {
    $user = User::factory()->create();

    $this->post('/reset-password', [
        'token' => 'not-the-token',
        'email' => $user->email,
        'password' => 'a brand new password',
        'password_confirmation' => 'a brand new password',
    ])->assertSessionHasErrors(['email' => 'This reset link has expired or was already used. Ask for a new one below.']);

    $this->post('/reset-password', [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    expect(Hash::check('password', $user->fresh()?->password ?? ''))->toBeTrue();
});

it('sends a queued, themed Markdown mail with the reset link', function (): void {
    $user = User::factory()->create(['first_name' => 'Ana']);
    $notification = new ResetPasswordNotification('the-token');

    $mail = $notification->toMail($user);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($mail)->toBeInstanceOf(ResetPasswordMail::class);

    $mail->assertTo($user->email)
        ->assertHasSubject('Reset your ReserveVision password')
        ->assertSeeInHtml('Hi Ana, reset your password')
        ->assertSeeInHtml('/reset-password/the-token?email='.urlencode($user->email), false)
        ->assertSeeInHtml('#5B3E96'); // ube buttons and links from the ReserveVision mail theme
});
