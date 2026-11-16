<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\GenderPolicy;
use App\Enums\PropertyKind;
use App\Models\Amenity;
use App\Models\Property;
use App\Support\Places\PlaceLists;

/**
 * The props of `Landlord/Properties/Form`, for a new property (null) and an existing one.
 * The address dropdowns (slice 4.4): every province, and for an existing property the cities of
 * its province and the barangays of its city, so the form opens filled without a request. Other
 * lists come from the `/places/…` endpoints when the owner picks another province or city.
 */
final class PropertyFormProps
{
    /**
     * @return array<string, mixed>
     */
    public static function for(?Property $property): array
    {
        $locked = $property?->hasBeenPublished() ?? false;
        $city = $property?->linkedCity;

        return [
            'property' => $property === null ? null : [
                'id' => $property->id,
                'kind' => $property->kind->value,
                'name' => $property->name,
                'slug' => $property->slug,
                'address' => $property->address,
                'province_id' => $city?->province_id,
                'city_id' => $property->city_id,
                'barangay_id' => $property->barangay_id,
                // What a property from before slice 4.4 says, shown under an empty dropdown.
                'barangay_text' => $property->barangay_id === null ? $property->barangay : null,
                'city_text' => $property->city_id === null ? $property->city : null,
                'gender_policy' => $property->gender_policy->value,
                'amenities' => $property->amenities()->pluck('amenities.id')->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all(),
                'house_rules' => $property->house_rules,
                'payment_instructions' => $property->payment_instructions,
            ],
            // The slug and the city are the public URL: locked once the property has been published.
            'locked' => $locked,
            'provinces' => PlaceLists::provinces(),
            'cities' => $city === null ? [] : PlaceLists::cities($city->province_id),
            'barangays' => $city === null ? [] : PlaceLists::barangays($city->id),
            'amenities' => Amenity::query()->orderBy('name')->get()->map(fn (Amenity $amenity): array => [
                'id' => $amenity->id,
                'name' => $amenity->name,
            ])->all(),
            'kinds' => array_map(fn (PropertyKind $kind): string => $kind->value, PropertyKind::cases()),
            'genderPolicies' => array_map(fn (GenderPolicy $policy): string => $policy->value, GenderPolicy::cases()),
        ];
    }
}
