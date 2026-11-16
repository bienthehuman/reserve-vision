<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GenderPolicy;
use App\Enums\PropertyKind;
use App\Models\LandlordAccount;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cities = ['Cebu City' => 'Cebu', 'Davao City' => 'Davao del Sur', 'Quezon City' => 'Metro Manila', 'Baguio' => 'Benguet', 'Iloilo City' => 'Iloilo'];
        $kinds = ['Residences', 'Boarding House', 'Apartments', 'Dormitory'];
        $city = array_keys($cities)[fake()->numberBetween(0, count($cities) - 1)];
        $name = fake()->lastName().' '.$kinds[fake()->numberBetween(0, count($kinds) - 1)];

        return [
            'landlord_account_id' => LandlordAccount::factory(),
            'kind' => fake()->randomElement(PropertyKind::cases()),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'address' => fake()->buildingNumber().' '.fake()->streetName(),
            'barangay' => 'Barangay '.fake()->numberBetween(1, 200),
            'city' => $city,
            'city_slug' => Str::slug($city),
            'province' => $cities[$city],
            'gender_policy' => GenderPolicy::Any,
            'house_rules' => 'No smoking. Quiet hours 10pm to 6am. The reservation fee is refundable if declined.',
            'payment_instructions' => 'GCash 0917 123 4567 (J. Santos) or BPI 1234-5678-90.',
            'is_published' => false,
            'first_published_at' => null,
            'archived_at' => null,
        ];
    }

    /**
     * Visible to renters: published, and its landlord account is verified
     * (only verified accounts can publish).
     */
    public function published(): static
    {
        return $this->state(['is_published' => true, 'first_published_at' => now()])
            ->afterCreating(function (Property $property): void {
                LandlordAccount::query()
                    ->whereKey($property->landlord_account_id)
                    ->where('is_verified', false)
                    ->update(['is_verified' => true, 'verified_at' => now()]);
            });
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }

    public function girlsOnly(): static
    {
        return $this->state(['gender_policy' => GenderPolicy::FemaleOnly]);
    }
}
