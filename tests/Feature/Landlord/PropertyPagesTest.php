<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

// Slice 3.2: the read-only property pages (matrix row "View own account's properties, rooms, spaces and photos").

it('lists only the account\'s properties', function (): void {
    $owner = landlordUser();
    $mine = Property::factory()->create(['landlord_account_id' => accountIdOf($owner), 'name' => 'Mine House']);
    Room::factory()->for($mine)->count(2)->create();
    Property::factory()->published()->create(['name' => 'Theirs House']);

    $response = $this->actingAs($owner)->get('/landlord/properties');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Landlord/Properties/Index')
        ->has('properties', 1)
        ->where('properties.0.name', 'Mine House')
        ->where('properties.0.room_count', 2)
        ->where('properties.0.is_published', false));
    expect($response->getContent())->not->toContain('Theirs House');
});

it('shows a property with its rooms, rates and spaces; the rate form is the owner\'s', function (LandlordRole $role, bool $canChangeRate): void {
    $owner = landlordUser();
    $account = accountIdOf($owner);
    $user = $role === LandlordRole::Owner ? $owner : landlordUserOf($account, $role);
    $property = Property::factory()->published()->create(['landlord_account_id' => $account]);
    Room::factory()->for($property)->create(['name' => 'Private One', 'monthly_rate' => 650000]);
    Room::factory()->shared(beds: 3)->for($property)->create(['name' => 'Shared Two', 'monthly_rate' => 300050, 'deposit_months' => 2]);

    $this->actingAs($user)->get("/landlord/properties/{$property->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Landlord/Properties/Show')
        ->where('property.name', $property->name)
        ->has('rooms', 2)
        ->where('rooms.0.name', 'Private One')
        ->where('rooms.0.monthly_rate', 650000)
        ->where('rooms.0.can_change_rate', $canChangeRate)
        ->has('rooms.0.spaces', 1)
        ->where('rooms.1.name', 'Shared Two')
        ->where('rooms.1.deposit_months', 2)
        ->has('rooms.1.spaces', 3)
        ->where('rooms.1.can_change_rate', $canChangeRate));
})->with([
    'owner' => [LandlordRole::Owner, true],
    'caretaker' => [LandlordRole::Caretaker, false],
]);

it('keeps renters and guests out', function (): void {
    $property = Property::factory()->published()->create();

    $this->get('/landlord/properties')->assertRedirect('/login');
    $this->actingAs(User::factory()->renter()->create())->get("/landlord/properties/{$property->id}")->assertForbidden();
});
