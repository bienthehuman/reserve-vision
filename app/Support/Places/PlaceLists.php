<?php

declare(strict_types=1);

namespace App\Support\Places;

use App\Models\Barangay;
use App\Models\City;
use App\Models\Province;

/**
 * The three dropdown lists of the property form (slice 4.4), for its props and for the
 * `/places/…` endpoints. Sorted in PHP, naturally ("Barangay 2" before "Barangay 10")
 * and the same on MySQL and MariaDB whatever the collation; barangays by district first.
 */
final class PlaceLists
{
    /**
     * @return list<array{id: int, name: string, slug: string}>
     */
    public static function provinces(): array
    {
        return self::sorted(Province::query()->get(['id', 'name', 'slug'])
            ->map(fn (Province $province): array => ['id' => $province->id, 'name' => $province->name, 'slug' => $province->slug])
            ->all());
    }

    /**
     * @return list<array{id: int, name: string, slug: string}>
     */
    public static function cities(int $provinceId): array
    {
        return self::sorted(City::query()->where('province_id', $provinceId)->get(['id', 'name', 'slug'])
            ->map(fn (City $city): array => ['id' => $city->id, 'name' => $city->name, 'slug' => $city->slug])
            ->all());
    }

    /**
     * @return list<array{id: int, name: string, district: string|null}>
     */
    public static function barangays(int $cityId): array
    {
        $barangays = Barangay::query()->where('city_id', $cityId)->get(['id', 'name', 'district'])
            ->map(fn (Barangay $barangay): array => ['id' => $barangay->id, 'name' => $barangay->name, 'district' => $barangay->district])
            ->all();

        usort($barangays, fn (array $a, array $b): int => strnatcasecmp($a['district'] ?? '', $b['district'] ?? '')
            ?: strnatcasecmp($a['name'], $b['name'])
            ?: $a['id'] <=> $b['id']);

        return $barangays;
    }

    /**
     * @template T of array{id: int, name: string}
     *
     * @param  array<T>  $items
     * @return list<T>
     */
    private static function sorted(array $items): array
    {
        usort($items, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']);

        return $items;
    }
}
