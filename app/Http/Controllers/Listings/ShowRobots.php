<?php

declare(strict_types=1);

namespace App\Http\Controllers\Listings;

use Illuminate\Http\Response;

/** GET /robots.txt: production points to the sitemap and keeps crawlers out of the private pages; every other environment disallows everything. */
final class ShowRobots
{
    public function __invoke(): Response
    {
        $lines = app()->isProduction()
            ? [
                'User-agent: *',
                'Disallow: /landlord',
                'Disallow: /reservations',
                'Disallow: /invitations',
                'Disallow: /login',
                'Disallow: /register',
                '',
                'Sitemap: '.url('/sitemap.xml'),
            ]
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
