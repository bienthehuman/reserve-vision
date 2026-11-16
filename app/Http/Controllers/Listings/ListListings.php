<?php

declare(strict_types=1);

namespace App\Http\Controllers\Listings;

use App\Actions\ReserveSpace;
use App\Enums\PropertyKind;
use App\Models\Amenity;
use App\Models\Property;
use App\Support\BusinessDate;
use App\Support\Listings\ListingCards;
use App\Support\Places\PlaceLists;
use App\Support\Search\ListingSearch;
use App\Support\Search\SearchFilters;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /listings: search results (slice 5.2). Every filter is a query-string parameter, validated by
 * `SearchFilters` (bad values are dropped), so a result page can be shared and works without JavaScript.
 */
final class ListListings
{
    public const int PER_PAGE = 12;

    public function __invoke(Request $request): Response
    {
        $filters = SearchFilters::fromRequest($request);

        $page = ListingSearch::properties($filters)
            ->paginate(self::PER_PAGE, page: max(1, (int) $request->query('page', 1)));

        $summaries = ListingSearch::summaries(array_values(array_map(fn (Property $property): int => $property->id, $page->items())), $filters);

        $listings = array_map(function (array $card) use ($summaries, $filters): array {
            $summary = $summaries[$card['id']] ?? null;
            $stay = array_filter(['move_in' => $filters->moveIn?->toDateString(), 'months' => $filters->moveIn === null ? null : (string) $filters->months]);

            return [
                ...$card,
                // The cheapest room that matches, not the cheapest room there is.
                'from_rate' => $summary['from_rate'] ?? $card['from_rate'],
                'rooms_matching' => $summary['rooms'] ?? 0,
                'beds_free' => $summary['beds_free'] ?? null,
                'url' => $card['url'].($stay === [] ? '' : '?'.http_build_query($stay).'#rooms'),
            ];
        }, ListingCards::of(collect($page->items())));

        return Inertia::render('Listings/Index', [
            'listings' => $listings,
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'prevHref' => $page->currentPage() > 1 ? self::href($filters, $page->currentPage() - 1) : null,
            'nextHref' => $page->hasMorePages() ? self::href($filters, $page->currentPage() + 1) : null,
            'filtered' => $filters->isFiltered(),
            'filters' => self::values($filters),
            'window' => ['first' => BusinessDate::today()->toDateString(), 'last' => BusinessDate::today()->addDays(ReserveSpace::MOVE_IN_WINDOW_DAYS)->toDateString()],
            'moveOut' => $filters->moveOut()?->toDateString(),
            'options' => [
                'provinces' => PlaceLists::provinces(),
                'cities' => $filters->province === null ? [] : PlaceLists::cities($filters->province->id),
                'barangays' => $filters->city === null ? [] : PlaceLists::barangays($filters->city->id),
                'amenities' => Amenity::query()->orderBy('name')->get(['slug', 'name'])
                    ->map(fn (Amenity $amenity): array => ['value' => $amenity->slug, 'label' => $amenity->name])->all(),
                'kinds' => array_map(fn (PropertyKind $kind): string => $kind->value, PropertyKind::cases()),
            ],
        ]);
    }

    /**
     * What the form shows: the understood filters, with empty strings for the ones not set.
     *
     * @return array<string, string|list<string>>
     */
    public static function values(SearchFilters $filters): array
    {
        return [
            'province' => $filters->province->slug ?? '',
            'city' => $filters->city->slug ?? '',
            'barangay' => $filters->barangay === null ? '' : (string) $filters->barangay->id,
            'min_price' => $filters->minRate === null ? '' : (string) intdiv($filters->minRate, 100),
            'max_price' => $filters->maxRate === null ? '' : (string) intdiv($filters->maxRate, 100),
            'kind' => $filters->kind->value ?? '',
            'gender' => $filters->gender ?? '',
            'amenities' => $filters->amenities,
            'move_in' => $filters->moveIn?->toDateString() ?? '',
            'months' => (string) $filters->months,
            'sort' => $filters->sort,
        ];
    }

    private static function href(SearchFilters $filters, int $page): string
    {
        $query = $filters->toQuery() + ($page > 1 ? ['page' => (string) $page] : []);

        return '/listings'.($query === [] ? '' : '?'.http_build_query($query));
    }
}
