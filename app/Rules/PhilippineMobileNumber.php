<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\PhilippineMobile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A Philippine mobile number in any of the forms App\Support\PhilippineMobile accepts. */
final class PhilippineMobileNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhilippineMobile::normalize($value) === null) {
            $fail(__('Enter a Philippine mobile number, like 0917 123 4567.'));
        }
    }
}
