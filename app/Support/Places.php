<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The cities a property can be in: `database/data/places.php`, the same file
 * the demo seeder draws from. A city's centre is where `lat`/`lng` start.
 *
 * @phpstan-type Place array{city: string, slug: string, lat: float, lng: float, spread: float, rate: float, barangays: list<string>}
 */
final class Places
{
    /**
     * @return list<Place>
     */
    public static function all(): array
    {
        /** @var list<Place> $places */
        $places = require database_path('data/places.php');

        return $places;
    }

    /**
     * @return Place|null
     */
    public static function find(string $slug): ?array
    {
        foreach (self::all() as $place) {
            if ($place['slug'] === $slug) {
                return $place;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_map(fn (array $place): string => $place['slug'], self::all());
    }

    /** A coordinate as `lat`/`lng` store it: DECIMAL(9,6), as a string. */
    public static function coordinate(float|string $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }
}
