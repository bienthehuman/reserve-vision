<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\RegisterLandlord;
use App\Actions\RegisterRenter;
use App\Data\RegistrationDetails;
use App\Enums\NameSuffix;
use App\Enums\UserType;
use App\Models\User;
use App\Support\PhilippineMobile;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Fortify's registration hook: validates the form, normalizes the phone number
 * and hands over to RegisterRenter or RegisterLandlord ("I want to: rent a
 * place / list my property").
 */
final class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private readonly RegisterRenter $registerRenter,
        private readonly RegisterLandlord $registerLandlord,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        /** @var array{type: string, first_name: string, middle_name?: string|null, last_name: string, suffix?: string|null, email: string, phone: string, password: string, business_name?: string|null} $valid */
        $valid = Validator::make($input, [
            'type' => ['required', Rule::enum(UserType::class)],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', Rule::enum(NameSuffix::class)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'phone' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || PhilippineMobile::normalize($value) === null) {
                    $fail(__('Enter a Philippine mobile number, like 0917 123 4567.'));
                }
            }],
            'password' => $this->passwordRules(),
            'business_name' => ['exclude_unless:type,landlord', 'required', 'string', 'max:255'],
        ], [
            'type.required' => __('Choose whether you want to rent a place or list your property.'),
            'type.enum' => __('Choose whether you want to rent a place or list your property.'),
            'email.unique' => __('There is already an account with this email. Log in instead, or reset your password.'),
            'business_name.required' => __('Enter the name renters will see, like "Santos Family Rentals" or your own name.'),
        ], [
            'first_name' => __('first name'),
            'middle_name' => __('middle name'),
            'last_name' => __('last name'),
            'business_name' => __('business or display name'),
            'phone' => __('mobile number'),
        ])->validate();

        $details = new RegistrationDetails(
            firstName: $valid['first_name'],
            middleName: $valid['middle_name'] ?? null,
            lastName: $valid['last_name'],
            suffix: isset($valid['suffix']) ? NameSuffix::from($valid['suffix']) : null,
            email: $valid['email'],
            phone: (string) PhilippineMobile::normalize($valid['phone']),
            password: $valid['password'],
        );

        return UserType::from($valid['type']) === UserType::Landlord
            ? $this->registerLandlord->handle($details, (string) ($valid['business_name'] ?? ''))
            : $this->registerRenter->handle($details);
    }
}
