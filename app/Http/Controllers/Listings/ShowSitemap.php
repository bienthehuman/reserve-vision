<?php

declare(strict_types=1);

namespace App\Http\Controllers\Listings;

use App\Support\Listings\SitemapCache;
use Illuminate\Http\Response;

/** GET /sitemap.xml: the home page and every published listing (SitemapCache). */
final class ShowSitemap
{
    public function __invoke(): Response
    {
        return response(SitemapCache::xml(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
