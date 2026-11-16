<?php

declare(strict_types=1);

/*
| The cities the demo data uses (slice 2.2), in the order the seeder deals them out:
| the PSGC slug (slice 4.4: the places tables, filled by `places:import`, hold every
| city, its province and its real barangays) and `rate`, which nudges prices: 1.0 is
| the national middle, Makati is dearer. Manila comes first: the "last bed" house is there.
|
| @return list<array{slug: string, rate: float}>
*/
return [
    ['slug' => 'manila', 'rate' => 1.00],
    ['slug' => 'quezon-city', 'rate' => 1.05],
    ['slug' => 'makati', 'rate' => 1.20],
    ['slug' => 'cebu-city', 'rate' => 0.95],
    ['slug' => 'davao-city', 'rate' => 0.90],
    ['slug' => 'baguio', 'rate' => 0.95],
    ['slug' => 'iloilo-city', 'rate' => 0.85],
];
