<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/** Fortify's password-reset hook: the token was checked by the password broker before this runs. */
final class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * @param  array<string, mixed>  $input
     */
    public function reset(User $user, array $input): void
    {
        /** @var array{password: string} $valid */
        $valid = Validator::make($input, ['password' => $this->passwordRules()])->validate();

        $user->forceFill(['password' => $valid['password']])->save();
    }
}
