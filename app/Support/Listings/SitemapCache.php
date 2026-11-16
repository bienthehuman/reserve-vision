<?php

declare(strict_types=1);

namespace App\Support\Listings;

use App\Models\Property;
use Illuminate\Support\Facades\Cache;

/**
 * The sitemap XML, cached. Anything that can change which listings exist (or their
 * last-modified time) forgets it after commit: see `AppServiceProvider::bootSitemapInvalidation()`.
 * The hour is a safety net, not the mechanism.
 */
final class SitemapCache
{
    public const string KEY = 'sitemap.xml';

    public const int SECONDS = 3600;

    public static function xml(): string
    {
        return Cache::remember(self::KEY, self::SECONDS, self::build(...));
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    private static function build(): string
    {
        $entries = [['loc' => url('/'), 'lastmod' => null]];

        Property::query()->published()->orderBy('properties.id')
            ->get(['properties.id', 'properties.city_slug', 'properties.slug', 'properties.updated_at'])
            ->each(function (Property $property) use (&$entries): void {
                $entries[] = [
                    'loc' => url(ListingCards::url($property)),
                    'lastmod' => $property->updated_at->toAtomString(),
                ];
            });

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($entries as $entry) {
            $xml .= '  <url><loc>'.htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES).'</loc>'
                .($entry['lastmod'] === null ? '' : '<lastmod>'.$entry['lastmod'].'</lastmod>')
                ."</url>\n";
        }

        return $xml.'</urlset>'."\n";
    }
}
