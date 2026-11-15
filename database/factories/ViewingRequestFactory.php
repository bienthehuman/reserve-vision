<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ViewingRequestStatus;
use App\Models\Property;
use App\Models\User;
use App\Models\ViewingRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ViewingRequest>
 */
class ViewingRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'landlord_account_id' => fn (array $attributes): mixed => Property::query()
                ->whereKey($attributes['property_id'])->value('landlord_account_id'),
            'renter_id' => User::factory()->renter(),
            'preferred_at' => now()->addDays(3),
            'status' => ViewingRequestStatus::Requested,
            'landlord_note' => null,
            'decided_by' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(['status' => ViewingRequestStatus::Accepted]);
    }

    public function declined(): static
    {
        return $this->state(['status' => ViewingRequestStatus::Declined, 'landlord_note' => 'Not available that day.']);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => ViewingRequestStatus::Cancelled]);
    }
}
