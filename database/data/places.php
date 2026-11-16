<?php

declare(strict_types=1);

/*
| Cities and barangays for the demo data (slice 2.2). Barangay names are real
| (Manila uses its districts, because its barangays are numbers). `province`
| is what an address says after the city: "Metro Manila" for the capital
| region's cities, which belong to no province. `rate` nudges prices: 1.0 is
| the national middle, Makati is dearer. These are also the cities a property
| can be in (App\Support\Places).
|
| @return list<array{city: string, slug: string, province: string, rate: float, barangays: list<string>}>
*/
return [
    ['city' => 'Manila', 'slug' => 'manila', 'province' => 'Metro Manila', 'rate' => 1.00,
        'barangays' => ['Sampaloc', 'Malate', 'Ermita', 'Paco', 'Pandacan', 'Santa Mesa', 'Tondo', 'Binondo']],
    ['city' => 'Quezon City', 'slug' => 'quezon-city', 'province' => 'Metro Manila', 'rate' => 1.05,
        'barangays' => ['Loyola Heights', 'Batasan Hills', 'Commonwealth', 'Kamuning', 'Tandang Sora', 'Matandang Balara', 'Pinyahan', 'Teachers Village East', 'Sikatuna Village']],
    ['city' => 'Makati', 'slug' => 'makati', 'province' => 'Metro Manila', 'rate' => 1.20,
        'barangays' => ['Poblacion', 'Bel-Air', 'Guadalupe Nuevo', 'San Antonio', 'Pio del Pilar', 'Bangkal', 'La Paz', 'Palanan', 'Olympia']],
    ['city' => 'Cebu City', 'slug' => 'cebu-city', 'province' => 'Cebu', 'rate' => 0.95,
        'barangays' => ['Lahug', 'Guadalupe', 'Mabolo', 'Capitol Site', 'Banilad', 'Apas', 'Talamban', 'Labangon', 'Basak San Nicolas']],
    ['city' => 'Davao City', 'slug' => 'davao-city', 'province' => 'Davao del Sur', 'rate' => 0.90,
        'barangays' => ['Matina', 'Buhangin', 'Bajada', 'Talomo', 'Lanang', 'Catalunan Grande', 'Ecoland', 'Maa']],
    ['city' => 'Baguio', 'slug' => 'baguio', 'province' => 'Benguet', 'rate' => 0.95,
        'barangays' => ['Aurora Hill', 'Irisan', 'Pacdal', 'Camp 7', 'Bakakeng Central', 'Engineers Hill', 'Gibraltar', 'Quezon Hill']],
    ['city' => 'Iloilo City', 'slug' => 'iloilo-city', 'province' => 'Iloilo', 'rate' => 0.85,
        'barangays' => ['Jaro', 'Mandurriao', 'La Paz', 'Molo', 'Arevalo', 'Lapuz', 'Tabuc Suba']],
];
