<?php

declare(strict_types=1);

/*
| T11 (BUILD_PLAN): a listing page fetched without JavaScript has its title, price and location in the HTML,
| plus the sharing tags. This is the raw server response: no browser, no hydration. The same page with
| JavaScript switched off, and axe on it, is e2e/listings.spec.ts.
*/

it('T11: the raw HTML has the title, from-price, place, room rates and the OG and JSON-LD tags', function (): void {
    [$property, $private, $shared] = listedProperty();

    $response = $this->get('/listings/manila/casa-verde')->assertOk();
    $html = (string) $response->getContent();

    // What a person reads.
    expect($html)
        ->toContain('<h1 class="h2 mb-2">Casa Verde</h1>')
        ->toContain('Sampaloc, Manila')
        ->toContain('From ')
        ->toContain('₱3,500')   // the from-price, and the shared room's rate
        ->toContain('₱6,500')   // the private room's rate
        ->toContain('Corner Room')
        ->toContain('Bedspace Room')
        ->toContain('Open in Maps')
        ->toContain('4 of 4 beds free');

    // What a crawler or a link preview reads, each once.
    $head = explode('</head>', $html)[0];
    $once = fn (string $needle): int => substr_count($head, $needle);

    expect($once('<title'))->toBe(1)
        ->and($once('rel="canonical"'))->toBe(1)
        ->and($once('property="og:title"'))->toBe(1)
        ->and($once('property="og:image"'))->toBe(1)
        ->and($once('name="twitter:card" content="summary_large_image"'))->toBe(1)
        ->and($once('type="application/ld+json"'))->toBe(1);
});
