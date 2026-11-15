<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LandlordRole;
use App\Models\LandlordAccount;
use App\Models\LandlordMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LandlordMember>
 */
class LandlordMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'landlord_account_id' => LandlordAccount::factory(),
            'user_id' => User::factory()->landlord(),
            'role' => LandlordRole::Caretaker,
        ];
    }

    public function owner(): static
    {
        return $this->state(['role' => LandlordRole::Owner]);
    }

    public function caretaker(): static
    {
        return $this->state(['role' => LandlordRole::Caretaker]);
    }
}
