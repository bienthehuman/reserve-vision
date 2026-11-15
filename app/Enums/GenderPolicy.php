<?php

declare(strict_types=1);

namespace App\Enums;

/** Mirrors the `properties.gender_policy` CHECK. Shown on the listing, not enforced. */
enum GenderPolicy: string
{
    case Any = 'any';
    case MaleOnly = 'male_only';
    case FemaleOnly = 'female_only';
}
