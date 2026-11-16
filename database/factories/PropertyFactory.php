<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GenderPolicy;
use App\Enums\PropertyKind;
use App\Models\Barangay;
use App\Models\LandlordAccount;
use App\Models\Property;
use App\Support\Places\AddressCopies;
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
        $kinds = ['Residences', 'Boarding House', 'Apartments', 'Dormitory'];
        $name = fake()->lastName().' '.$kinds[fake()->numberBetween(0, count($kinds) - 1)];

        return [
            'landlord_account_id' => LandlordAccount::factory(),
            'kind' => fake()->randomElement(PropertyKind::cases()),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'address' => fake()->buildingNumber().' '.fake()->streetName(),
            // A made-up place of its own (slice 4.4); `at()` puts it in a given barangay. The text
            // columns are the copies AddressCopies writes, derived once the barangay exists.
            'barangay_id' => Barangay::factory(),
            'city_id' => fn (array $attributes): ?int => self::copies($attributes['barangay_id'] ?? null)['city_id'] ?? null,
            'barangay' => fn (array $attributes): string => self::copies($attributes['barangay_id'] ?? null)['barangay'] ?? 'Not set',
            'city' => fn (array $attributes): string => self::copies($attributes['barangay_id'] ?? null)['city'] ?? 'Manila',
            'city_slug' => fn (array $attributes): string => self::copies($attributes['barangay_id'] ?? null)['city_slug'] ?? 'manila',
            'province' => fn (array $attributes): string => self::copies($attributes['barangay_id'] ?? null)['province'] ?? 'Metro Manila',
            'gender_policy' => GenderPolicy::Any,
            'house_rules' => 'No smoking. Quiet hours 10pm to 6am. The reservation fee is refundable if declined.',
            'payment_instructions' => 'GCash 0917 123 4567 (J. Santos) or BPI 1234-5678-90.',
            'is_published' => false,
            'first_published_at' => null,
            'archived_at' => null,
        ];
    }

    /** In this barangay, with the text copies the actions would write. */
    public function at(Barangay $barangay): static
    {
        return $this->state(AddressCopies::of($barangay));
    }

    /**
     * From before slice 4.4: text only, not linked to the places tables.
     */
    public function unlinked(string $barangay = 'Sampaloc', string $city = 'Manila', string $citySlug = 'manila', string $province = 'Metro Manila'): static
    {
        return $this->state([
            'barangay_id' => null, 'city_id' => null,
            'barangay' => $barangay, 'city' => $city, 'city_slug' => $citySlug, 'province' => $province,
        ]);
    }

    /** @var array{0: int, 1: array{city_id: int, barangay_id: int, barangay: string, city: string, city_slug: string, province: string}}|null */
    private static ?array $lastCopies = null;

    /**
     * The copies for the definition's barangay, looked up once per barangay.
     *
     * @return array{city_id: int, barangay_id: int, barangay: string, city: string, city_slug: string, province: string}|null
     */
    private static function copies(mixed $barangayId): ?array
    {
        if (! is_int($barangayId)) {
            return null;
        }
        if (self::$lastCopies === null || self::$lastCopies[0] !== $barangayId) {
            self::$lastCopies = [$barangayId, AddressCopies::of(Barangay::query()->with('city.province')->findOrFail($barangayId))];
        }

        return self::$lastCopies[1];
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
