<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\RegistrationDetails;
use App\Enums\UserType;
use App\Models\User;

/** A renter signs up (ACCESS.md, "Actors"). Unverified until they click the emailed link. */
final class RegisterRenter
{
    public function handle(RegistrationDetails $details): User
    {
        return User::create([...$details->userAttributes(), 'type' => UserType::Renter]);
    }
}
