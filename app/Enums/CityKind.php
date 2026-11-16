<?php

declare(strict_types=1);

namespace App\Enums;

/** What the PSGC calls a place a barangay belongs to (slice 4.4): a city or a municipality. */
enum CityKind: string
{
    case City = 'city';
    case Municipality = 'municipality';
}
