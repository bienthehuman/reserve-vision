<?php

declare(strict_types=1);

use App\Enums\UserType;
use App\Models\User;
use App\Support\DemoLogins;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

it('leaves the demo panel out of the login page without APP_DEMO', function (): void {
    config(['app.demo' => false]);

    $response = $this->get('/login');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->missing('demoLogins'));
    // Not hidden: absent. Neither the server-rendered markup nor the page JSON mentions it.
    expect($response->getContent())
        ->not->toContain('Demo accounts')
        ->not->toContain('owner@example.com')
        ->not->toContain('demoLogins');
});

it('shows the demo panel on the login page with APP_DEMO=true', function (): void {
    config(['app.demo' => true]);

    $response = $this->get('/login');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('demoLogins', count(DemoLogins::all())));

    $html = (string) $response->getContent();
    expect($html)->toMatch('/<h2[^>]*id="demo-logins-title"[^>]*>Demo accounts<\/h2>/');
    foreach (DemoLogins::all() as $login) {
        expect($html)->toContain($login['email']);
    }
});

it('lists accounts the demo seeder creates, already verified, with the right type', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Manila'));
    config(['app.demo' => true]);
    (new DemoSeeder)->run();

    foreach (DemoLogins::all() as $login) {
        $user = User::query()->where('email', $login['email'])->sole();

        expect(Hash::check($login['password'], $user->password))->toBeTrue()
            ->and($user->hasVerifiedEmail())->toBeTrue()
            ->and($user->type)->toBe($login['role'] === 'Renter' ? UserType::Renter : UserType::Landlord);
    }
});
