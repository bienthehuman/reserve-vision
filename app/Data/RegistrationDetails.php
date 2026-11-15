<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\NameSuffix;

/**
 * What a person gives when they sign up, already validated and normalized
 * (App\Actions\Fortify\CreateNewUser). Input to RegisterRenter and RegisterLandlord.
 */
final readonly class RegistrationDetails
{
    public function __construct(
        public string $firstName,
        public ?string $middleName,
        public string $lastName,
        public ?NameSuffix $suffix,
        public string $email,
        /** Normalized: "09XXXXXXXXX" (App\Support\PhilippineMobile). */
        public string $phone,
        /** Plain text; the User model's `hashed` cast hashes it. */
        public string $password,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function userAttributes(): array
    {
        return [
            'first_name' => $this->firstName,
            'middle_name' => $this->middleName,
            'last_name' => $this->lastName,
            'suffix' => $this->suffix,
            'email' => $this->email,
            'phone' => $this->phone,
            'password' => $this->password,
        ];
    }
}
