<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentProofStatus;
use App\Models\PaymentProof;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentProof>
 */
class PaymentProofFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'landlord_account_id' => fn (array $attributes): mixed => Reservation::query()
                ->whereKey($attributes['reservation_id'])->value('landlord_account_id'),
            'path' => 'proofs/'.fake()->uuid().'.jpg',
            'amount' => fn (array $attributes): mixed => Reservation::query()
                ->whereKey($attributes['reservation_id'])->value('reservation_fee'),
            'reference_no' => fake()->numerify('############'),
            'status' => PaymentProofStatus::Pending,
            'uploaded_at' => now(),
        ];
    }

    public function accepted(): static
    {
        return $this->state(['status' => PaymentProofStatus::Accepted, 'reviewed_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status' => PaymentProofStatus::Rejected,
            'reviewed_at' => now(),
            'rejection_reason' => 'The amount does not match.',
        ]);
    }
}
