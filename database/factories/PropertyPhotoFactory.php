<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PhotoStatus;
use App\Models\Property;
use App\Models\PropertyPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyPhoto>
 */
class PropertyPhotoFactory extends Factory
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
            'path' => 'properties/'.fake()->uuid(),
            'alt' => null,
            'width' => 1600,
            'height' => 1200,
            'sort' => 1,
            'status' => PhotoStatus::Ready,
        ];
    }

    public function processing(): static
    {
        return $this->state(['status' => PhotoStatus::Processing, 'width' => null, 'height' => null]);
    }

    public function failed(): static
    {
        return $this->state(['status' => PhotoStatus::Failed, 'width' => null, 'height' => null]);
    }
}
