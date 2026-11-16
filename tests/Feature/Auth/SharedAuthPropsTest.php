<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('offers "Log in" and "Sign up" to guests', function (): void {
    $response = $this->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user', null));
    expect($response->getContent())
        ->toMatch('/<a[^>]*href="\/login"[^>]*>Log in<\/a>/')
        ->toMatch('/<a[^>]*href="\/register"[^>]*>Sign up<\/a>/');
});

it('gives a landlord the account menu: their name, the way to the dashboard and Log out', function (): void {
    $landlord = landlordUser();

    $html = (string) $this->actingAs($landlord)->get('/')->getContent();

    expect($html)
        ->toContain(e($landlord->full_name))
        ->toMatch('/<button[^>]*class="user-menu__trigger"[^>]*aria-expanded="false"/')
        ->toMatch('/<a[^>]*href="\/landlord"[^>]*>(<svg.*?<\/svg>)?Dashboard<\/a>/')
        ->toMatch('/<button[^>]*>(<svg.*?<\/svg>)?Log out<\/button>/')
        ->not->toMatch('/>Sign up</');
});

it('shows a renter no Dashboard link', function (): void {
    $html = (string) $this->actingAs(User::factory()->renter()->create())->get('/')->getContent();

    expect($html)->toMatch('/>Log out</')->not->toMatch('/>Dashboard</');
});

it('shares only what the header needs, never the email or phone', function (): void {
    $user = User::factory()->renter()->create(['email' => 'private@example.com', 'phone' => '09170000001']);

    $response = $this->actingAs($user)->get('/');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user', [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'full_name' => $user->full_name,
            'type' => 'renter',
            'email_verified' => true,
        ]));
    expect($response->getContent())->not->toContain('private@example.com')->not->toContain('09170000001');
});

it('sets the session cookie HttpOnly and SameSite=Lax', function (): void {
    $cookie = collect($this->get('/login')->headers->getCookies())
        ->firstOrFail(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});
