<?php

declare(strict_types=1);

namespace App\Data;

/** What `places:import` did (App\Support\Places\PlacesImporter). */
final readonly class PlacesImportReport
{
    public function __construct(
        /** @var array<string, int> rows in each file */
        public array $rows,
        /** @var array<string, int> rows new to each table */
        public array $added,
        /** @var list<string> "table: name (code)" for rows no longer in the files, which are kept */
        public array $gone,
        public int $linkedCities,
        public int $linkedBarangays,
        /** Properties still without a city: their `city_slug` matches none. */
        public int $unlinkedCities,
        /** Properties still without a barangay: the owner chooses it on the form. */
        public int $unlinkedBarangays,
        public float $seconds,
    ) {}
}
