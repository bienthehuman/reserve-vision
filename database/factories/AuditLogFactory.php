<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\LandlordAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory()->landlord(),
            'landlord_account_id' => LandlordAccount::factory(),
            'action' => 'ConfirmReservation',
            'subject_type' => 'reservation',
            'subject_id' => fake()->numberBetween(1, 9999),
            'before' => ['status' => 'proof_uploaded'],
            'after' => ['status' => 'confirmed'],
        ];
    }
}
