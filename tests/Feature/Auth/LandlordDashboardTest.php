<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('shows a verified owner the placeholder dashboard', function (): void {
    $owner = landlordUser();
    $account = $owner->landlordMember()->sole()->landlordAccount()->sole();

    $response = $this->actingAs($owner)->get('/landlord');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Landlord/Dashboard')
        ->where('account.name', $account->name)
        ->where('account.isVerified', true)
        ->where('role', 'owner'));

    expect($response->getContent())
        ->toContain('Dashboard arrives in phase 4')
        ->not->toContain('awaiting verification');
});

it('tells the owner of an unverified account that it is awaiting verification (ACCESS.md flow A)', function (): void {
    $response = $this->actingAs(landlordUser(verifiedAccount: false))->get('/landlord');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('account.isVerified', false));
    expect($response->getContent())->toContain('Your account is awaiting verification');
});

it('lets a caretaker in too', function (): void {
    $this->actingAs(landlordUser(role: LandlordRole::Caretaker))->get('/landlord')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('role', 'caretaker'));
});

it('forbids renters', function (): void {
    $this->actingAs(User::factory()->renter()->create())->get('/landlord')->assertForbidden();
});

it('sends guests to log in', function (): void {
    $this->get('/landlord')->assertRedirect('/login');
});
