<?php

declare(strict_types=1);

namespace App\Http\Controllers\Places;

use App\Models\City;
use App\Support\Places\PlaceLists;
use Illuminate\Http\JsonResponse;

/** GET /places/cities/{city:slug}/barangays: the city's barangays, Manila's with their district (slice 4.4). Public. */
final class ListBarangays
{
    public function __invoke(City $city): JsonResponse
    {
        return PlacesResponse::of(PlaceLists::barangays($city->id));
    }
}
