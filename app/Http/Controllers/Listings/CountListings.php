<?php

declare(strict_types=1);

namespace App\Http\Controllers\Listings;

use App\Support\Search\ListingSearch;
use App\Support\Search\SearchFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /listings/count: how many places the filters in the query string match (the filter sheet's "Show N places" button). */
final class CountListings
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['total' => ListingSearch::properties(SearchFilters::fromRequest($request))->toBase()->getCountForPagination()])
            ->header('Cache-Control', 'no-store');
    }
}
