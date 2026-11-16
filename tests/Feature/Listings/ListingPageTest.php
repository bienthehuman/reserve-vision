<?php

declare(strict_types=1);

use App\Models\LandlordAccount;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\PropertyPhotoFactory;
use Inertia\Testing\AssertableInertia;

/*
| The public listing page (slice 5.1). "Now" is 2027-03-15 10:00 Manila, so the booking window is Mar 15 – Jun 13.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

it('shows the property, its rooms and the free beds for the stay in the link', function (): void {
    [$property, , $shared] = listedProperty();
    Reservation::factory()->confirmed()->for($shared->spaces()->orderBy('id')->firstOrFail())
        ->create(['move_in' => '2027-04-01', 'move_out' => '2027-10-01']);

    $this->get('/listings/manila/casa-verde?move_in=2027-04-01&months=6')
        ->assertOk()
        ->assertSee('Casa Verde')
        ->assertSee('12 Lacson Ave, Barangay 395, Sampaloc, Manila')
        ->assertSee('₱3,500')
        ->assertSee('₱6,500')
        ->assertSee('3 of 4 beds free')
        ->assertSee('No visitors after 10 PM')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Listings/Show')
            ->where('listing.from_rate', 350000)
            ->where('stay', ['move_in' => '2027-04-01', 'months' => 6, 'move_out' => '2027-10-01'])
            ->where('window.first', '2027-03-15')
            ->where('window.last', '2027-06-13')
            ->has('rooms', 2)
            // Cheapest first; each room carries its anchor id.
            ->where('rooms.0.id', $shared->id)
            ->where('rooms.0.free_spaces', 3)
            ->where('rooms.0.space_count', 4)
            ->where('rooms.1.deposit_months', 2)
            ->where('rooms.1.advance_months', 1)
            ->where('rooms.1.free_spaces', 1));
});

it('defaults to moving in today for 6 months and ignores a malformed stay', function (string $query): void {
    listedProperty();

    $this->get("/listings/manila/casa-verde{$query}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stay', ['move_in' => '2027-03-15', 'months' => 6, 'move_out' => '2027-09-15']));
})->with([
    'no query' => [''],
    'bad date and months' => ['?move_in=2027-02-30&months=13'],
    'not a date' => ['?move_in=tomorrow&months=abc'],
]);

it('is a 404 unless the property is published, not archived and of a verified account, in that city', function (Closure $change, string $url): void {
    $property = Property::factory()->published()->at(placeIn('manila'))->create(['name' => 'Casa Verde', 'slug' => 'casa-verde']);
    Room::factory()->for($property)->create();
    $change($property);

    $this->get($url)->assertNotFound();
})->with([
    'unpublished' => [fn (Property $p) => $p->update(['is_published' => false]), '/listings/manila/casa-verde'],
    'archived' => [fn (Property $p) => $p->update(['archived_at' => now()]), '/listings/manila/casa-verde'],
    'unverified account' => [fn (Property $p) => LandlordAccount::query()->whereKey($p->landlord_account_id)->update(['is_verified' => false, 'verified_at' => null]), '/listings/manila/casa-verde'],
    'wrong city' => [fn () => null, '/listings/cebu-city/casa-verde'],
    'unknown slug' => [fn () => null, '/listings/manila/nothing-here'],
]);

it('never shows the landlord\'s contact details, the payment instructions or other renters', function (): void {
    [$property, $private] = listedProperty();
    $other = User::factory()->create(['first_name' => 'Zephyrina', 'phone' => '09171234567']);
    reserve($other, $private, '2027-04-01');

    $account = LandlordAccount::query()->findOrFail($property->landlord_account_id);
    $account->forceFill(['contact_phone' => '09998887777'])->save();

    $html = $this->get('/listings/manila/casa-verde')->assertOk()->getContent();

    expect($html)->not->toContain('09998887777')
        ->not->toContain('GCash')
        ->not->toContain('Ate Lorna')
        ->not->toContain('Zephyrina')
        ->not->toContain('09171234567')
        ->not->toContain($other->email);
    // The landlord account's name is shown.
    expect($html)->toContain($account->name);
});

it('hides rooms that are archived', function (): void {
    [, $private] = listedProperty();
    $private->update(['archived_at' => now()]);

    $this->get('/listings/manila/casa-verde')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('rooms', 1)->where('listing.from_rate', 350000));
});

it('shows only ready photos, cover first, with the owner\'s alt text or a default', function (): void {
    [$property] = listedProperty();
    PropertyPhotoFactory::new()->processing()->for($property)->create(['sort' => 3]);

    $this->get('/listings/manila/casa-verde')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('listing.photos', 2)
            ->where('listing.photos.0.alt', 'The front gate of Casa Verde')
            ->where('listing.photos.1.alt', 'Photo 2 of Casa Verde'));
});

it('loads the cover at once with high priority and every other image lazily', function (): void {
    listedProperty();

    $html = $this->get('/listings/manila/casa-verde')->getContent();
    preg_match_all('/<img\b[^>]*>/i', (string) $html, $matches);
    $images = array_values(array_filter($matches[0], fn (string $tag): bool => str_contains($tag, '/media/photos/')));

    expect($images)->toHaveCount(2);
    expect($images[0])->toContain('loading="eager"')->toMatch('/fetchpriority="high"/i')->toContain('width="1600"')->toContain('height="1200"')->toMatch('/srcset=/i');
    expect($images[1])->toContain('loading="lazy"')->not->toMatch('/fetchpriority/i');
});

it('shows a landlord of another account the same page as anyone', function (): void {
    listedProperty();
    $landlord = landlordUser();

    $this->actingAs($landlord)->get('/listings/manila/casa-verde')
        ->assertOk()
        ->assertSee('Casa Verde')
        ->assertSee('To reserve a room, log in with a renter account')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('listing.photos', 2)->has('rooms', 2));
});

it('points a renter to their own hold in the room instead of offering another', function (): void {
    [, $private] = listedProperty();
    $renter = User::factory()->create();
    $reservation = reserve($renter, $private, '2027-04-01');

    $this->actingAs($renter)->get('/listings/manila/casa-verde')
        ->assertOk()
        ->assertSee('View your hold')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rooms.1.open_reservation', ['id' => $reservation->id, 'space_label' => 'Whole room'])
            ->where('rooms.0.open_reservation', null));

    $this->actingAs(User::factory()->create())->get('/listings/manila/casa-verde')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rooms.1.open_reservation', null));
});

it('links to a maps search of the address', function (): void {
    listedProperty();

    $this->get('/listings/manila/casa-verde')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('listing.maps_url', 'https://www.google.com/maps/search/?api=1&query='.rawurlencode('12 Lacson Ave, Barangay 395, Sampaloc, Manila, Metro Manila, Philippines')));
});

it('has a reserve form per room that posts to the reserve route with a token, as a plain form', function (): void {
    [, $private, $shared] = listedProperty();

    $html = (string) $this->get('/listings/manila/casa-verde?move_in=2027-04-01&months=3')->getContent();

    expect($html)->toContain("action=\"/rooms/{$private->id}/reservations\"")
        ->toContain("action=\"/rooms/{$shared->id}/reservations\"")
        ->toContain('name="_token"')
        ->toContain('name="move_in" value="2027-04-01"')
        ->toContain('name="months" value="3"')
        ->toContain("id=\"room-{$private->id}\"");
});

it('reserves through a plain form post and sends the renter to their hold', function (): void {
    [, $private] = listedProperty();
    $renter = User::factory()->create();

    $this->actingAs($renter)->from('/listings/manila/casa-verde')
        ->post("/rooms/{$private->id}/reservations", ['move_in' => '2027-04-01', 'months' => 6])
        ->assertRedirect();

    expect(Reservation::query()->count())->toBe(1);
});

it('puts a broken rule from a plain form post back on the listing page', function (): void {
    [, $private] = listedProperty();
    reserve(User::factory()->create(), $private, '2027-04-01');

    $this->actingAs(User::factory()->create())->from('/listings/manila/casa-verde?move_in=2027-04-01&months=6')
        ->post("/rooms/{$private->id}/reservations", ['move_in' => '2027-04-01', 'months' => 6])
        ->assertRedirect('/listings/manila/casa-verde?move_in=2027-04-01&months=6')
        ->assertSessionHas('error');
});
