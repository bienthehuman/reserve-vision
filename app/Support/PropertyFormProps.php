<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\GenderPolicy;
use App\Enums\PropertyKind;
use App\Models\Amenity;
use App\Models\Property;

/**
 * The props of `Landlord/Properties/Form`, for a new property (null) and an existing one.
 * `places` carries each city's centre, which the form uses as the default for lat and lng.
 */
final class PropertyFormProps
{
    /**
     * @return array<string, mixed>
     */
    public static function for(?Property $property): array
    {
        $locked = $property?->hasBeenPublished() ?? false;

        return [
            'property' => $property === null ? null : [
                'id' => $property->id,
                'kind' => $property->kind->value,
                'name' => $property->name,
                'slug' => $property->slug,
                'city' => $property->city_slug,
                'barangay' => $property->barangay,
                'address' => $property->address,
                'lat' => $property->lat,
                'lng' => $property->lng,
                'gender_policy' => $property->gender_policy->value,
                'amenities' => $property->amenities()->pluck('amenities.id')->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all(),
                'house_rules' => $property->house_rules,
                'payment_instructions' => $property->payment_instructions,
            ],
            // The slug and the city are the public URL: locked once the property has been published.
            'locked' => $locked,
            'places' => array_map(fn (array $place): array => [
                'slug' => $place['slug'],
                'city' => $place['city'],
                'lat' => Places::coordinate($place['lat']),
                'lng' => Places::coordinate($place['lng']),
            ], Places::all()),
            'amenities' => Amenity::query()->orderBy('name')->get()->map(fn (Amenity $amenity): array => [
                'id' => $amenity->id,
                'name' => $amenity->name,
            ])->all(),
            'kinds' => array_map(fn (PropertyKind $kind): string => $kind->value, PropertyKind::cases()),
            'genderPolicies' => array_map(fn (GenderPolicy $policy): string => $policy->value, GenderPolicy::cases()),
        ];
    }
}
