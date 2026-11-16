<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\Fortify\PasswordValidationRules;
use App\Data\RegistrationDetails;
use App\Enums\NameSuffix;
use App\Rules\PhilippineMobileNumber;
use App\Support\PhilippineMobile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /invitations/{token}. Logged in: nothing to validate, the button is the whole form.
 * Not logged in: the short sign-up form (name, mobile number, password), the same rules as
 * /register. There is no email field: the account gets the invited email (AcceptInvitation).
 */
final class AcceptInvitationRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return true; // the token is the authorization; AcceptInvitation checks it under a lock
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->user() !== null) {
            return [];
        }

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', Rule::enum(NameSuffix::class)],
            'phone' => ['required', 'string', 'max:32', new PhilippineMobileNumber],
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'first_name' => __('first name'),
            'middle_name' => __('middle name'),
            'last_name' => __('last name'),
            'phone' => __('mobile number'),
        ];
    }

    /** The validated sign-up form; the email is the invitation's, filled in by AcceptInvitation. */
    public function details(string $email): RegistrationDetails
    {
        $suffix = $this->validated('suffix');

        return new RegistrationDetails(
            firstName: $this->string('first_name')->toString(),
            middleName: $this->filled('middle_name') ? $this->string('middle_name')->toString() : null,
            lastName: $this->string('last_name')->toString(),
            suffix: is_string($suffix) ? NameSuffix::from($suffix) : null,
            email: $email,
            phone: (string) PhilippineMobile::normalize($this->string('phone')->toString()),
            password: $this->string('password')->toString(),
        );
    }
}
