<?php

declare(strict_types=1);

namespace App\Enums;

/** Mirrors the `properties.kind` CHECK. */
enum PropertyKind: string
{
    case Apartment = 'apartment';
    case BoardingHouse = 'boarding_house';
}
