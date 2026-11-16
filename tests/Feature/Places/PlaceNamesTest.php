<?php

declare(strict_types=1);

use App\Support\Places\PlaceNames;

// Slice 4.4: a PSGC name becomes the name people write (App\Support\Places\PlaceNames).

/** A small country: enough provinces and other names for every rule. */
function namesOf(string $psgcName): string
{
    $provinces = ['Cebu', 'Davao del Sur', 'Davao del Norte', 'Iloilo', 'La Union', 'Pampanga', 'Bataan', 'Quezon', 'Isabela', 'Basilan'];
    $bareNames = PlaceNames::bareNames([
        'City of Makati', 'Quezon City', 'City of Cebu', 'City of Davao', 'City of Iloilo', 'City of Baguio', 'City of Manila',
        'City of San Fernando', 'City of San Fernando', 'San Fernando', 'Island Garden City of Samal', 'Samal',
        'Science City of Muñoz', 'City of Naga', 'City of Naga', 'City of Isabela', 'Isabela', 'City of Lucenaa/',
        'City of Cagayan De Oro ', 'Pasay City',
    ]);

    return PlaceNames::city($psgcName, $provinces, $bareNames);
}

it('drops the "City of" title where the bare name is what people say', function (string $psgc, string $shown): void {
    expect(namesOf($psgc))->toBe($shown);
})->with([
    'Makati' => ['City of Makati', 'Makati'],
    'Manila' => ['City of Manila', 'Manila'],
    'Baguio' => ['City of Baguio', 'Baguio'],
    'a science city' => ['Science City of Muñoz', 'Muñoz'],
    'a footnote marker, trailing spaces' => ['City of Lucenaa/', 'Lucena'],
    'De inside a name' => ['City of Cagayan De Oro ', 'Cagayan de Oro'],
]);

it('keeps "City" where the bare name is a province, the start of one, or another place', function (string $psgc, string $shown): void {
    expect(namesOf($psgc))->toBe($shown);
})->with([
    'Cebu, a province' => ['City of Cebu', 'Cebu City'],
    'Iloilo, a province' => ['City of Iloilo', 'Iloilo City'],
    'Davao, the start of Davao del Sur' => ['City of Davao', 'Davao City'],
    'Isabela, a province and a municipality' => ['City of Isabela', 'Isabela City'],
    'San Fernando, in two provinces and a municipality' => ['City of San Fernando', 'San Fernando City'],
    'Naga, in two provinces' => ['City of Naga', 'Naga City'],
    'Island Garden City of Samal: Samal is also in Bataan' => ['Island Garden City of Samal', 'Samal City'],
]);

it('leaves names the PSA already writes as people do, and municipalities, as they are', function (string $psgc, string $shown): void {
    expect(namesOf($psgc))->toBe($shown);
})->with([
    'Quezon City' => ['Quezon City', 'Quezon City'],
    'Pasay City' => ['Pasay City', 'Pasay City'],
    'a municipality' => ['San Fernando', 'San Fernando'],
    'a municipality with "Del" inside' => ['San Jose Del Monte', 'San Jose del Monte'],
    'a municipality that starts with "Del"' => ['Del Carmen', 'Del Carmen'],
]);

it('cleans any name: spaces and footnote markers, but not a slash inside a name', function (): void {
    expect(PlaceNames::clean("  Bitan-o/Dalipay \n"))->toBe('Bitan-o/Dalipay')
        ->and(PlaceNames::clean('City of  Lucenaa/'))->toBe('City of Lucena')
        ->and(PlaceNames::clean('Barangay 1/'))->toBe('Barangay 1/');
});

it('names Manila\'s districts as people do', function (): void {
    expect(PlaceNames::district('Tondo I/II'))->toBe('Tondo')
        ->and(PlaceNames::district('Sampaloc '))->toBe('Sampaloc');
});
