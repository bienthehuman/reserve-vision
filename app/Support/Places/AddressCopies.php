<?php

declare(strict_types=1);

namespace App\Support\Places;

use App\Models\Barangay;

/**
 * A property's place columns for a barangay (slice 4.4): the two links, and the text
 * copies that lists, `full_address` and the listing URL (`city_slug`) read. Only the
 * property actions (and factories) write them, always through here, so the copies equal
 * the linked rows (`tests/Feature/Places/AddressCopiesTest.php`).
 */
final class AddressCopies
{
    /**
     * @return array{city_id: int, barangay_id: int, barangay: string, city: string, city_slug: string, province: string}
     */
    public static function of(Barangay $barangay): array
    {
        $city = $barangay->city;

        return [
            'city_id' => $city->id,
            'barangay_id' => $barangay->id,
            'barangay' => $barangay->label,
            'city' => $city->name,
            'city_slug' => $city->slug,
            'province' => $city->province->name,
        ];
    }
}
