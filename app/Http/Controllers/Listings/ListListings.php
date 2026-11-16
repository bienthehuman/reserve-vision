<?php

declare(strict_types=1);

namespace App\Http\Controllers\Listings;

use App\Models\Property;
use App\Support\Listings\ListingCards;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** GET /listings: every published listing, newest first, a plain paginated list. Slice 5.2 turns it into search results. */
final class ListListings
{
    public const int PER_PAGE = 12;

    public function __invoke(Request $request): Response
    {
        $page = Property::query()->published()
            ->orderByDesc('properties.first_published_at')->orderByDesc('properties.id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Listings/Index', [
            'listings' => ListingCards::of(collect($page->items())),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'prevHref' => $page->previousPageUrl() === null ? null : '/listings'.($page->currentPage() > 2 ? '?page='.($page->currentPage() - 1) : ''),
            'nextHref' => $page->nextPageUrl() === null ? null : '/listings?page='.($page->currentPage() + 1),
        ]);
    }
}
