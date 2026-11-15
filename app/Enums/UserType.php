<?php

declare(strict_types=1);

namespace App\Enums;

/** Mirrors the `users.type` CHECK. */
enum UserType: string
{
    case Renter = 'renter';
    case Landlord = 'landlord';
}
