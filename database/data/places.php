<?php

declare(strict_types=1);

/*
| Cities and barangays for the demo data (slice 2.2). Barangay names are real
| (Manila uses its districts, because its barangays are numbers). `lat`/`lng`
| are the approximate centre of the city and `spread` is how far, in degrees,
| a property may sit from it, so every point lands inside the city's built-up
| area. `rate` nudges prices: 1.0 is the national middle, Makati is dearer.
|
| @return list<array{city: string, slug: string, lat: float, lng: float, spread: float, rate: float, barangays: list<string>}>
*/
return [
    ['city' => 'Manila', 'slug' => 'manila', 'lat' => 14.6042, 'lng' => 120.9822, 'spread' => 0.018, 'rate' => 1.00,
        'barangays' => ['Sampaloc', 'Malate', 'Ermita', 'Paco', 'Pandacan', 'Santa Mesa', 'Tondo', 'Binondo']],
    ['city' => 'Quezon City', 'slug' => 'quezon-city', 'lat' => 14.6488, 'lng' => 121.0509, 'spread' => 0.035, 'rate' => 1.05,
        'barangays' => ['Loyola Heights', 'Batasan Hills', 'Commonwealth', 'Kamuning', 'Tandang Sora', 'Matandang Balara', 'Pinyahan', 'Teachers Village East', 'Sikatuna Village']],
    ['city' => 'Makati', 'slug' => 'makati', 'lat' => 14.5547, 'lng' => 121.0244, 'spread' => 0.018, 'rate' => 1.20,
        'barangays' => ['Poblacion', 'Bel-Air', 'Guadalupe Nuevo', 'San Antonio', 'Pio del Pilar', 'Bangkal', 'La Paz', 'Palanan', 'Olympia']],
    ['city' => 'Cebu City', 'slug' => 'cebu-city', 'lat' => 10.3157, 'lng' => 123.8854, 'spread' => 0.03, 'rate' => 0.95,
        'barangays' => ['Lahug', 'Guadalupe', 'Mabolo', 'Capitol Site', 'Banilad', 'Apas', 'Talamban', 'Labangon', 'Basak San Nicolas']],
    ['city' => 'Davao City', 'slug' => 'davao-city', 'lat' => 7.0731, 'lng' => 125.6128, 'spread' => 0.035, 'rate' => 0.90,
        'barangays' => ['Matina', 'Buhangin', 'Bajada', 'Talomo', 'Lanang', 'Catalunan Grande', 'Ecoland', 'Maa']],
    ['city' => 'Baguio', 'slug' => 'baguio', 'lat' => 16.4023, 'lng' => 120.5960, 'spread' => 0.02, 'rate' => 0.95,
        'barangays' => ['Aurora Hill', 'Irisan', 'Pacdal', 'Camp 7', 'Bakakeng Central', 'Engineers Hill', 'Gibraltar', 'Quezon Hill']],
    ['city' => 'Iloilo City', 'slug' => 'iloilo-city', 'lat' => 10.7202, 'lng' => 122.5621, 'spread' => 0.02, 'rate' => 0.85,
        'barangays' => ['Jaro', 'Mandurriao', 'La Paz', 'Molo', 'Arevalo', 'Lapuz', 'Tabuc Suba']],
];
