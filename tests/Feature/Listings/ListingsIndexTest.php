<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\Listings\ListListings;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\Room;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function publishedHouse(string $name, int $minutesAgo = 0, int $rate = 400000): Property
{
    $property = Property::factory()->published()->at(placeIn('manila'))->create([
        'name' => $name, 'slug' => Str::slug($name), 'first_published_at' => now()->subMinutes($minutesAgo),
    ]);
    Room::factory()->for($property)->create(['monthly_rate' => $rate]);
    Room::factory()->for($property)->create(['monthly_rate' => $rate + 100000]);

    return $property;
}

it('shows the newest listings on the home page as cards with the cheapest rate and the cover', function (): void {
    foreach (range(1, HomeController::FEATURED + 2) as $n) {
        $house = publishedHouse("House {$n}", minutesAgo: 100 - $n, rate: 300000 + $n * 10000);
        PropertyPhoto::factory()->for($house)->create(['alt' => "Cover of house {$n}"]);
    }
    Property::factory()->at(placeIn('manila'))->create(['name' => 'Draft House', 'slug' => 'draft-house']);

    $this->get('/')
        ->assertOk()
        ->assertSee('House 8')
        ->assertDontSee('Draft House')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('featured', HomeController::FEATURED)
            ->where('featured.0.name', 'House 8')
            ->where('featured.0.url', '/listings/manila/house-8')
            ->where('featured.0.from_rate', 380000)
            ->where('featured.0.barangay', 'Barangay 395, Sampaloc')
            ->where('featured.0.city', 'Manila')
            ->where('featured.0.cover.alt', 'Cover of house 8')
            ->where('featured.0.cover.width', 480)
            ->where('featured.0.cover.height', 360));
});

it('shows the home page without listings', function (): void {
    $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('featured', 0));
});

it('pages through every published listing, newest first', function (): void {
    foreach (range(1, ListListings::PER_PAGE + 1) as $n) {
        publishedHouse("House {$n}", minutesAgo: 100 - $n);
    }

    $this->get('/listings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Listings/Index')
        ->has('listings', ListListings::PER_PAGE)
        ->where('listings.0.name', 'House 13')
        ->where('total', 13)->where('page', 1)->where('lastPage', 2)
        ->where('prevHref', null)->where('nextHref', '/listings?page=2'));

    $this->get('/listings?page=2')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('listings', 1)->where('listings.0.name', 'House 1')->where('prevHref', '/listings')->where('nextHref', null));
});
