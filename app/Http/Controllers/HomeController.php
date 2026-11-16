<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReserveSpace;
use App\Models\Property;
use App\Support\BusinessDate;
use App\Support\Listings\ListingCards;
use Inertia\Inertia;
use Inertia\Response;

final class HomeController
{
    /** The newest listings shown under the pitch. */
    public const int FEATURED = 6;

    public function __invoke(): Response
    {
        return Inertia::render('Public/Home', [
            'pitch' => __('Reserve apartments and boarding-house beds online, with no double bookings.'),
            // The cities with something to find, for the search form's "Where": a plain select, so it works without JavaScript.
            'cities' => Property::query()->published()->distinct()->orderBy('properties.city')
                ->get(['properties.city_slug', 'properties.city'])
                ->map(fn (Property $property): array => ['value' => $property->city_slug, 'label' => $property->city])->all(),
            'window' => ['first' => BusinessDate::today()->toDateString(), 'last' => BusinessDate::today()->addDays(ReserveSpace::MOVE_IN_WINDOW_DAYS)->toDateString()],
            'featured' => ListingCards::of(Property::query()->published()
                ->orderByDesc('properties.first_published_at')->orderByDesc('properties.id')
                ->limit(self::FEATURED)->get()),
        ]);
    }
}
