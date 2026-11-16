<?php

declare(strict_types=1);

namespace App\Support\Listings;

use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\Room;
use Illuminate\Support\Collection;

/**
 * What a listing card shows (home page, the listings list): the property, its cover photo
 * and the cheapest listed room. Two extra queries for any number of properties, all through
 * the public scopes.
 */
final class ListingCards
{
    /** The width `PhotoProcessor` gives a thumbnail. */
    public const int THUMB_WIDTH = 480;

    /**
     * @param  Collection<int, Property>  $properties
     * @return list<array{id: int, url: string, name: string, kind: string, gender_policy: string, barangay: string, city: string, from_rate: int|null, cover: array{src: string, width: int, height: int, alt: string}|null}>
     */
    public static function of(Collection $properties): array
    {
        $ids = $properties->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, PropertyPhoto> $covers first (lowest sort) shown photo of each property */
        $covers = PropertyPhoto::query()->shown()->whereIn('property_id', $ids)->get()->unique('property_id')->keyBy('property_id');

        /** @var Collection<int|string, mixed> $rates */
        $rates = Room::query()->listed()->whereIn('rooms.property_id', $ids)
            ->groupBy('rooms.property_id')
            ->selectRaw('rooms.property_id as property_id, min(rooms.monthly_rate) as from_rate')
            ->pluck('from_rate', 'property_id');

        return array_values($properties->map(function (Property $property) use ($covers, $rates): array {
            $cover = $covers->get($property->id);
            $rate = $rates->get($property->id);

            return [
                'id' => $property->id,
                'url' => self::url($property),
                'name' => $property->name,
                'kind' => $property->kind->value,
                'gender_policy' => $property->gender_policy->value,
                'barangay' => $property->barangay,
                'city' => $property->city,
                'from_rate' => is_numeric($rate) ? (int) $rate : null,
                'cover' => $cover instanceof PropertyPhoto ? self::thumb($cover, $property->name) : null,
            ];
        })->all());
    }

    public static function url(Property $property): string
    {
        return route('listings.show', ['city' => $property->city_slug, 'slug' => $property->slug], false);
    }

    /**
     * @return array{src: string, width: int, height: int, alt: string}|null
     */
    private static function thumb(PropertyPhoto $photo, string $propertyName): ?array
    {
        $src = $photo->thumb_url;

        if ($src === null) {
            return null;
        }

        $width = $photo->width ?? 0;
        $height = $photo->height ?? 0;

        return [
            'src' => $src,
            'width' => self::THUMB_WIDTH,
            // Thumbnails are 480 px wide and never taller than the original's ratio allows.
            'height' => $width > 0 ? max(1, (int) round($height * self::THUMB_WIDTH / $width)) : self::THUMB_WIDTH * 3 / 4,
            'alt' => $photo->altText($propertyName),
        ];
    }
}
