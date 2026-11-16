<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Property;
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
            'featured' => ListingCards::of(Property::query()->published()
                ->orderByDesc('properties.first_published_at')->orderByDesc('properties.id')
                ->limit(self::FEATURED)->get()),
        ]);
    }
}
