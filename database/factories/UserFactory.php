<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NameSuffix;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'middle_name' => null,
            'last_name' => fake()->lastName(),
            'suffix' => null,
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09'.fake()->numerify('#########'),
            'type' => UserType::Renter,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function withMiddleName(?string $middleName = null): static
    {
        return $this->state(fn (): array => ['middle_name' => $middleName ?? fake()->lastName()]);
    }

    public function withSuffix(NameSuffix $suffix = NameSuffix::Jr): static
    {
        return $this->state(['suffix' => $suffix]);
    }

    public function renter(): static
    {
        return $this->state(['type' => UserType::Renter]);
    }

    public function landlord(): static
    {
        return $this->state(['type' => UserType::Landlord]);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}
