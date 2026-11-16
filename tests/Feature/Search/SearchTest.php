<?php

declare(strict_types=1);

use App\Http\Controllers\Listings\ListListings;
use App\Models\Amenity;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// Search (slice 5.2). "Now" is frozen at 2027-03-15 10:00 Manila: the move-in window is Mar 15 to Jun 13.

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00:00', 'Asia/Manila'));
});

/** A published house with one private room at `$rate` centavos. */
function searchHouse(string $name, int $rate = 500000, string $city = 'manila', array $state = [], ?string $barangay = null): Property
{
    $property = Property::factory()->published()->at(placeIn($city, $barangay))->state([
        'name' => $name, 'slug' => Str::slug($name), 'first_published_at' => now()->subMinutes(abs(crc32($name)) % 1000), ...$state,
    ])->create();
    Room::factory()->for($property)->create(['name' => "{$name} room", 'monthly_rate' => $rate]);

    return $property;
}

/** The names of the properties on the results page, in order. */
function names(array $query = []): array
{
    $page = null;
    test()->get('/listings?'.http_build_query($query))->assertOk()->assertInertia(function (AssertableInertia $inertia) use (&$page): void {
        $page = $inertia->toArray()['props']['listings'];
    });

    return array_column($page, 'name');
}

it('lists every published house with no filters, cheapest first', function (): void {
    searchHouse('Pricey', 900000);
    searchHouse('Cheap', 300000);
    Property::factory()->at(placeIn('manila'))->create(['name' => 'Draft', 'slug' => 'draft']);

    expect(names())->toBe(['Cheap', 'Pricey']);
    expect(names(['sort' => 'newest']))->toHaveCount(2);
});

it('filters by city and by barangay (which must belong to the city)', function (): void {
    searchHouse('In Manila', city: 'manila');
    $sampaloc = searchHouse('Other Barangay', city: 'manila', barangay: 'Barangay 1');
    searchHouse('In Quezon', city: 'quezon-city');

    expect(names(['city' => 'manila']))->toEqualCanonicalizing(['In Manila', 'Other Barangay'])
        ->and(names(['city' => 'quezon-city']))->toBe(['In Quezon'])
        ->and(names(['city' => 'manila', 'barangay' => $sampaloc->barangay_id]))->toBe(['Other Barangay'])
        // A barangay of another city is dropped, not an error: the city's results stay.
        ->and(names(['city' => 'quezon-city', 'barangay' => $sampaloc->barangay_id]))->toBe(['In Quezon'])
        ->and(names(['city' => 'nowhere']))->toHaveCount(3);
});

it('filters by the monthly price of a room, and shows the cheapest room that matches', function (): void {
    $house = searchHouse('Two Rooms', 300000);
    Room::factory()->for($house)->create(['monthly_rate' => 800000]);
    searchHouse('Dear', 1200000);

    $this->get('/listings?'.http_build_query(['min_price' => '5000', 'max_price' => '9000']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('listings', 1)
            ->where('listings.0.name', 'Two Rooms')
            ->where('listings.0.from_rate', 800000) // the cheapest room that matches, not the cheapest room
            ->where('listings.0.rooms_matching', 1)
            ->where('listings.0.beds_free', null));

    expect(names(['max_price' => '2000']))->toBe([])
        ->and(names(['min_price' => '10000']))->toBe(['Dear'])
        // A maximum below the minimum is dropped.
        ->and(names(['min_price' => '9000', 'max_price' => '1000']))->toBe(['Dear']);
});

it('filters by kind, and a search for women or men also shows places open to everyone', function (): void {
    searchHouse('Open Apartment', state: ['kind' => 'apartment', 'gender_policy' => 'any']);
    searchHouse('Women House', state: ['kind' => 'boarding_house', 'gender_policy' => 'female_only']);
    searchHouse('Men House', state: ['kind' => 'boarding_house', 'gender_policy' => 'male_only']);

    expect(names(['kind' => 'apartment']))->toBe(['Open Apartment'])
        ->and(names(['kind' => 'boarding_house']))->toEqualCanonicalizing(['Women House', 'Men House'])
        ->and(names(['gender' => 'female']))->toEqualCanonicalizing(['Open Apartment', 'Women House'])
        ->and(names(['gender' => 'male']))->toEqualCanonicalizing(['Open Apartment', 'Men House'])
        ->and(names(['gender' => 'female', 'kind' => 'apartment']))->toBe(['Open Apartment']);

    $this->get('/listings?gender=female')->assertSee('women-only places and places open to everyone');
});

it('needs every chosen amenity', function (): void {
    [$wifi, $aircon] = [Amenity::factory()->create(['slug' => 'wifi', 'name' => 'Wi-Fi']), Amenity::factory()->create(['slug' => 'aircon', 'name' => 'Aircon'])];
    searchHouse('Both')->amenities()->attach([$wifi->id, $aircon->id]);
    searchHouse('Wifi Only')->amenities()->attach([$wifi->id]);
    searchHouse('None');

    expect(names(['amenities' => ['wifi']]))->toEqualCanonicalizing(['Both', 'Wifi Only'])
        ->and(names(['amenities' => ['wifi', 'aircon']]))->toBe(['Both'])
        ->and(names(['amenities' => ['wifi', 'made-up']]))->toEqualCanonicalizing(['Both', 'Wifi Only']);
});

it('keeps rooms with a free space for the whole stay, and counts the free beds', function (): void {
    $house = searchHouse('Full House', 500000);
    Reservation::factory()->confirmed()->create(['space_id' => onlySpace($house->rooms()->sole())->id, 'move_in' => '2027-04-15', 'move_out' => '2027-07-15']);

    $shared = Property::factory()->published()->at(placeIn('manila'))->state(['name' => 'Bunk House', 'slug' => 'bunk-house'])->create();
    $bunks = Room::factory()->shared(beds: 3)->for($shared)->create(['monthly_rate' => 300000]);
    Reservation::factory()->create(['space_id' => $bunks->spaces()->orderBy('id')->first()->id, 'move_in' => '2027-04-01', 'move_out' => '2027-05-01']);

    expect(names(['move_in' => '2027-04-20', 'months' => '1']))->toBe(['Bunk House'])
        // The stay ends the day the other begins (Mar 15 + 1 month = Apr 15): no clash.
        ->and(names(['move_in' => '2027-03-15', 'months' => '1']))->toEqualCanonicalizing(['Full House', 'Bunk House'])
        ->and(names(['move_in' => '2027-03-15', 'months' => '2']))->toBe(['Bunk House'])
        ->and(names(['move_in' => '2027-04-20', 'months' => '12']))->toBe(['Bunk House']);

    $this->get('/listings?move_in=2027-04-20&months=1')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('listings.0.beds_free', 2)
        ->where('listings.0.url', '/listings/manila/bunk-house?move_in=2027-04-20&months=1#rooms'));
});

it('ignores a move-in date outside the booking window, and months it cannot read', function (): void {
    searchHouse('House');

    expect(names(['move_in' => '2027-03-14']))->toBe(['House'])
        ->and(names(['move_in' => '2027-06-14']))->toBe(['House'])
        ->and(names(['move_in' => 'tomorrow', 'months' => '99']))->toBe(['House'])
        ->and(names(['move_in' => '2027-02-30']))->toBe(['House']);

    $this->get('/listings?move_in=2027-06-14')->assertInertia(fn (AssertableInertia $page) => $page->where('filters.move_in', '')->where('filtered', false));
});

it('answers every kind of garbage with the unfiltered list instead of an error', function (): void {
    searchHouse('House');

    $this->get('/listings?'.http_build_query([
        'city' => ['a'], 'province' => '../etc', 'barangay' => 'x', 'min_price' => '-5', 'max_price' => '1e9999', 'kind' => 'castle', 'gender' => 'robot',
        'amenities' => 'wifi', 'move_in' => ['x'], 'months' => 'many', 'sort' => 'random', 'page' => 'last',
    ]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('listings', 1)->where('filtered', false)->where('page', 1));
});

it('pages through the results and keeps the filters in the links', function (): void {
    foreach (range(1, ListListings::PER_PAGE + 1) as $n) {
        searchHouse("House {$n}", 300000 + $n * 1000);
    }
    searchHouse('Other City', city: 'quezon-city');

    $this->get('/listings?city=manila&max_price=9000&move_in=2027-04-01&months=3')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('listings', ListListings::PER_PAGE)
        ->where('total', ListListings::PER_PAGE + 1)
        ->where('prevHref', null)
        ->where('nextHref', '/listings?province=metro-manila&city=manila&max_price=9000&move_in=2027-04-01&months=3&page=2')
        ->where('options.provinces.0.name', fn (string $name): bool => $name !== ''));

    $this->get('/listings?city=manila&max_price=9000&page=2')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('listings', 1)
        ->where('prevHref', '/listings?province=metro-manila&city=manila&max_price=9000')
        ->where('nextHref', null));
});

it('is a plain GET form that works without JavaScript, with the chosen province already narrowing the city list', function (): void {
    searchHouse('House');
    $province = placeIn('manila')->city->province;

    $html = $this->get('/listings?province='.$province->slug)->assertOk()->getContent();

    expect($html)->toContain('action="/listings"')->toContain('method="get"')
        ->toContain('name="province"')->toContain('name="city"')->toContain('name="min_price"')->toContain('name="max_price"')
        ->toContain('name="move_in"')->toContain('name="months"')->toContain('name="kind"')->toContain('name="gender"')
        ->toContain('name="sort"')
        // The cities of the chosen province are in the HTML, so the form can go on without scripts.
        ->toContain('value="manila"');
});

it('shows an empty state with a link that clears the filters', function (): void {
    searchHouse('House');

    $this->get('/listings?max_price=100')->assertOk()
        ->assertSee('No places match these filters')
        ->assertSee('Clear filters')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('listings', 0)->where('filtered', true));
});

it('counts the places the filters match for the filter sheet button', function (): void {
    searchHouse('Cheap', 300000);
    searchHouse('Dear', 900000);

    $this->getJson('/listings/count?max_price=4000')->assertOk()->assertJson(['total' => 1]);
    $this->getJson('/listings/count')->assertOk()->assertJson(['total' => 2]);
    $this->getJson('/listings/count?max_price=1')->assertOk()->assertJson(['total' => 0]);
});

it('gives a logged-in landlord the same results as everyone, other accounts included', function (): void {
    $other = searchHouse('Other Account House', 400000);
    Reservation::factory()->confirmed()->create(['space_id' => onlySpace($other->rooms()->sole())->id, 'move_in' => '2027-04-01', 'move_out' => '2027-07-01']);
    searchHouse('Free House', 400000);
    $guestNames = names(['move_in' => '2027-04-10']);

    $this->actingAs(landlordUser());

    expect($guestNames)->toBe(['Free House'])
        ->and(names(['move_in' => '2027-04-10']))->toBe($guestNames)
        ->and(names())->toEqualCanonicalizing(['Other Account House', 'Free House']);
});

it('does not list rooms or properties that are archived, drafts, or of unverified accounts', function (): void {
    $archived = searchHouse('Archived Room House');
    $archived->rooms()->sole()->forceFill(['archived_at' => now()])->save();
    $unverified = searchHouse('Unverified House');
    $unverified->landlordAccount->forceFill(['is_verified' => false])->save();
    searchHouse('Listed House');

    expect(names())->toBe(['Listed House']);
});
