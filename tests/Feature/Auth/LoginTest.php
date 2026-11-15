<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

it('server-renders the login form', function (): void {
    $response = $this->get('/login');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Login'));

    expect($response->getContent())
        ->toContain('data-server-rendered="true"')
        ->toMatch('/<h1[^>]*>Log in<\/h1>/')
        ->toMatch('/<label[^>]*for="field-email"[^>]*>Email<\/label>/')
        ->toMatch('/<input[^>]*type="password"[^>]*name="password"/');
});

it('logs a renter in and sends them home', function (): void {
    $renter = User::factory()->renter()->create(['email' => 'renter@example.com']);

    $this->post('/login', ['email' => 'renter@example.com', 'password' => 'password'])
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($renter);
});

it('sends a landlord to the dashboard', function (): void {
    $landlord = landlordUser();

    $this->post('/login', ['email' => $landlord->email, 'password' => 'password'])
        ->assertRedirect('/landlord');

    $this->assertAuthenticatedAs($landlord);
});

it('treats the email case-insensitively', function (): void {
    User::factory()->create(['email' => 'renter@example.com']);

    $this->post('/login', ['email' => 'Renter@Example.COM', 'password' => 'password'])->assertRedirect('/');

    $this->assertAuthenticated();
});

it('regenerates the session on login', function (): void {
    $user = User::factory()->create();
    $this->get('/login');
    $before = session()->getId();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect(session()->getId())->not->toBe($before);
});

it('remembers the user only when asked', function (bool $remember): void {
    $user = User::factory()->create();

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => $remember]);

    $recaller = Auth::guard()->getRecallerName();
    $remember ? $response->assertCookie($recaller) : $response->assertCookieMissing($recaller);
})->with([
    'remember me' => true,
    'not remembered' => false,
]);

it('rejects a wrong password with a message in the project voice', function (): void {
    $user = User::factory()->create();

    $this->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'That email and password don\'t match an account. Check them and try again.']);

    $this->assertGuest();
});

it('rejects an unknown email and a missing password', function (): void {
    $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => 'nobody@example.com'])->assertSessionHasErrors('password');

    $this->assertGuest();
});

it('keeps the intended URL across login (ACCESS.md flow B)', function (): void {
    Route::middleware(['web', 'auth'])->get('/_test/members-only', fn (): string => 'ok');
    $renter = User::factory()->renter()->create();

    $this->get('/_test/members-only')->assertRedirect('/login');

    $this->post('/login', ['email' => $renter->email, 'password' => 'password'])
        ->assertRedirect('/_test/members-only');
});

it('sends a logged-in user away from the login and sign-up pages', function (): void {
    $this->actingAs(User::factory()->renter()->create())->get('/login')->assertRedirect('/');
    $this->actingAs(landlordUser())->get('/register')->assertRedirect('/landlord');
});
