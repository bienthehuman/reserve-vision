<?php

declare(strict_types=1);

namespace App\Http\Controllers\Places;

use App\Models\Province;
use App\Support\Places\PlaceLists;
use Illuminate\Http\JsonResponse;

/** GET /places/provinces/{province:slug}/cities: the province's cities and municipalities (slice 4.4). Public. */
final class ListCities
{
    public function __invoke(Province $province): JsonResponse
    {
        return PlacesResponse::of(PlaceLists::cities($province->id));
    }
}
