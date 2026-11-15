<?php

declare(strict_types=1);

namespace App\Exceptions\Isolation;

use LogicException;

/**
 * Something asked for the current landlord account where there is none: no
 * landlord user is logged in (a guest, a renter, a console command or a job),
 * or the landlord user has no membership. A programming error, never a
 * user-facing message: code that can run without a landlord user checks
 * CurrentLandlordAccount::exists() first, and jobs take the account id as an argument.
 */
final class NoCurrentLandlordAccount extends LogicException
{
    public static function make(): self
    {
        return new self('There is no current landlord account: no landlord user with a membership is logged in. Jobs and commands acting for an account take its id explicitly.');
    }
}
