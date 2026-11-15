<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\LandlordInvitation;

/**
 * A freshly created invitation and its plain token. The token is stored only as
 * a hash, so this is the one moment it exists: slice 4.3's email takes it from here.
 */
final readonly class NewInvitation
{
    public function __construct(
        public LandlordInvitation $invitation,
        public string $token,
    ) {}
}
