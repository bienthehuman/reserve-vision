<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LandlordRole;
use App\Models\LandlordAccount;
use App\Models\LandlordInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LandlordInvitation>
 */
class LandlordInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'landlord_account_id' => LandlordAccount::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => LandlordRole::Caretaker,
            'token_hash' => hash('sha256', Str::random(40)),
            'invited_by' => User::factory()->landlord(),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'revoked_at' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(['accepted_at' => now()]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }
}
