<?php

declare(strict_types=1);

namespace App\Enums;

/** Mirrors the `landlord_members.role` and `landlord_invitations.role` CHECKs. */
enum LandlordRole: string
{
    case Owner = 'owner';
    case Caretaker = 'caretaker';
}
