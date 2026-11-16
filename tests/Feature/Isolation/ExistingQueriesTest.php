<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Models\LandlordMember;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Support\Listings\ListingCards;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// The queries that existed before slice 3.1, checked with a landlord logged in
// (the case the isolation scope changes). The full review is in the 3.1 PR.
// "Now" is 2027-03-15 10:00 Manila, as in the room page tests.

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/** A shared room with 4 beds, one taken for Apr–Oct 2027 and one archived. */
function busySharedRoom(?int $accountId = null): Room
{
    $property = Property::factory()->published()->state($accountId === null ? [] : ['landlord_account_id' => $accountId]);
    $room = Room::factory()->shared(beds: 4)->for($property)->create(['name' => 'Blue Room']);
    $spaces = $room->spaces()->orderBy('id')->get();
    Reservation::factory()->confirmed()->for($spaces[0])->create(['move_in' => '2027-04-01', 'move_out' => '2027-10-01']);
    $spaces[3]->forceFill(['archived_at' => now()])->save();

    return $room;
}

/** The room's listing page with a stay. */
function listingUrlOf(Room $room, string $query = '?move_in=2027-04-01&months=6'): string
{
    return ListingCards::url(Property::query()->published()->findOrFail($room->property_id)).$query;
}

it('shows a landlord another account\'s listed room exactly as a guest sees it', function (LandlordRole $role): void {
    $room = busySharedRoom();
    $url = listingUrlOf($room);
    $guestProps = null;

    $this->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$guestProps): void {
        $guestProps = $page->toArray()['props'];
    });

    $this->actingAs(landlordUser(role: $role))->get($url)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Listings/Show')
            ->where('rooms.0.name', 'Blue Room')
            ->where('rooms.0.space_count', 3)
            ->where('rooms.0.free_spaces', 2)
            ->where('listing.name', $guestProps['listing']['name'])
            ->where('rooms.0.open_reservation', null));

    expect($guestProps['rooms'][0]['space_count'])->toBe(3)->and($guestProps['rooms'][0]['free_spaces'])->toBe(2);
})->with([LandlordRole::Owner, LandlordRole::Caretaker]);

it('shows a landlord their own listed room with the same counts', function (): void {
    $owner = landlordUser();
    $room = busySharedRoom(LandlordMember::query()->where('user_id', $owner->id)->value('landlord_account_id'));

    $this->actingAs($owner)->get(listingUrlOf($room))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rooms.0.space_count', 3)->where('rooms.0.free_spaces', 2));
});

it('is still a 404 on the public room redirect for the landlord\'s own unlisted room', function (): void {
    $owner = landlordUser();
    $account = LandlordMember::query()->where('user_id', $owner->id)->value('landlord_account_id');
    $room = Room::factory()->for(Property::factory()->state(['landlord_account_id' => $account, 'is_published' => false]))->create();

    $this->actingAs($owner)->get("/rooms/{$room->id}")->assertNotFound();
});

it('shows a renter their own hold through the policy, and another renter\'s is a 404', function (): void {
    $reservation = Reservation::factory()->create();
    $renter = User::query()->findOrFail($reservation->renter_id);

    $this->actingAs($renter)->get("/reservations/{$reservation->id}")->assertOk();
    $this->actingAs(User::factory()->renter()->create())->get("/reservations/{$reservation->id}")->assertNotFound();
});

it('shows the dashboard of the landlord\'s own account, from the membership', function (LandlordRole $role): void {
    $user = landlordUser(role: $role);
    $account = LandlordMember::query()->where('user_id', $user->id)->sole()->landlordAccount()->sole();

    $this->actingAs($user)->get('/landlord')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('account.slug', $account->slug)
            ->where('role', $role->value));
})->with([LandlordRole::Owner, LandlordRole::Caretaker]);
