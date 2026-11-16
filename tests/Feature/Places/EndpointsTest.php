<?php

declare(strict_types=1);

use App\Models\Barangay;
use App\Models\City;
use App\Providers\AppServiceProvider;

// Slice 4.4: the public lists behind the property form's dropdowns.

it('lists a province\'s cities and municipalities by slug, in natural order', function (): void {
    $manila = placeIn('manila')->city;
    $province = $manila->province;
    $quezon = placeIn('quezon-city')->city;
    $other = City::factory()->for($province)->create(['name' => 'Caloocan', 'slug' => 'caloocan']);

    $this->getJson("/places/provinces/{$province->slug}/cities")
        ->assertOk()
        ->assertExactJson([
            ['id' => $other->id, 'name' => 'Caloocan', 'slug' => 'caloocan'],
            ['id' => $manila->id, 'name' => 'Manila', 'slug' => 'manila'],
            ['id' => $quezon->id, 'name' => 'Quezon City', 'slug' => 'quezon-city'],
        ]);
});

it('lists a city\'s barangays grouped by district and in natural order, so Barangay 2 comes before Barangay 10', function (): void {
    $sampaloc = placeIn('manila');
    $city = $sampaloc->city;
    $ten = Barangay::factory()->for($city)->create(['name' => 'Barangay 10', 'district' => 'Tondo']);
    $two = Barangay::factory()->for($city)->create(['name' => 'Barangay 2', 'district' => 'Tondo']);
    $ermita = Barangay::factory()->for($city)->create(['name' => 'Barangay 659', 'district' => 'Ermita']);

    $this->getJson('/places/cities/manila/barangays')
        ->assertOk()
        ->assertExactJson([
            ['id' => $ermita->id, 'name' => 'Barangay 659', 'district' => 'Ermita'],
            ['id' => $sampaloc->id, 'name' => 'Barangay 395', 'district' => 'Sampaloc'],
            ['id' => $two->id, 'name' => 'Barangay 2', 'district' => 'Tondo'],
            ['id' => $ten->id, 'name' => 'Barangay 10', 'district' => 'Tondo'],
        ]);
});

it('answers an unknown slug with 404', function (string $url): void {
    placeIn('manila');

    $this->getJson($url)->assertNotFound();
})->with([
    'province' => ['/places/provinces/atlantis/cities'],
    'city' => ['/places/cities/atlantis/barangays'],
    'an id instead of a slug' => ['/places/cities/1/barangays'],
]);

it('is public and cacheable: no login, no session cookie, a week\'s Cache-Control', function (): void {
    placeIn('manila');

    $response = $this->get('/places/cities/manila/barangays')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=604800')
        ->and($response->headers->getCookies())->toBeEmpty();
});

it('limits each IP to '.AppServiceProvider::PLACES_PER_MINUTE.' requests a minute', function (): void {
    placeIn('manila');

    foreach (range(1, AppServiceProvider::PLACES_PER_MINUTE) as $unused) {
        $this->getJson('/places/cities/manila/barangays')->assertOk();
    }

    $this->getJson('/places/cities/manila/barangays')->assertTooManyRequests();
});
