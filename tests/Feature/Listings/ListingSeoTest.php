<?php

declare(strict_types=1);

use App\Models\Amenity;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Support\Listings\ListingSeo;
use Illuminate\Support\Str;

/**
 * The tags of a listing's <head>, read from the server-rendered HTML.
 *
 * @return array{html: string, head: string}
 */
function listingHead(string $url = '/listings/manila/casa-verde'): array
{
    $html = (string) test()->get($url)->assertOk()->getContent();

    return ['html' => $html, 'head' => explode('</head>', $html)[0]];
}

/** The content of the first `<meta {attribute}="{name}" content="…">` (attributes in either order). */
function metaContent(string $head, string $attribute, string $name): ?string
{
    $quoted = preg_quote($name, '/');
    foreach (["/<meta {$attribute}=\"{$quoted}\" content=\"([^\"]*)\"/", "/<meta content=\"([^\"]*)\" {$attribute}=\"{$quoted}\"/"] as $pattern) {
        if (preg_match($pattern, $head, $match) === 1) {
            return html_entity_decode($match[1]);
        }
    }

    return null;
}

it('builds the title from the name, the from-price and the place', function (): void {
    listedProperty();

    preg_match('/<title[^>]*>(.*?)<\/title>/s', listingHead()['head'], $match);

    expect(html_entity_decode($match[1]))->toBe('Casa Verde · Rooms from ₱3,500 in Barangay 395, Sampaloc, Manila | ReserveVision');
});

it('shortens a long title one part at a time', function (): void {
    $title = fn (string $name): string => ListingSeo::for(
        Property::factory()->at(placeIn('manila'))->make(['name' => $name, 'slug' => 'x']),
        [['id' => 1, 'name' => 'Room', 'monthly_rate' => 350000]],
        [],
        [],
    )['title'];

    expect($title('Casa Verde'))->toBe('Casa Verde · Rooms from ₱3,500 in Barangay 395, Sampaloc, Manila | ReserveVision')
        // The barangay goes first, then the price, then the place.
        ->and($title('The Residences at Casa Verde'))->toBe('The Residences at Casa Verde · Rooms from ₱3,500 in Manila | ReserveVision')
        ->and($title(trim(str_repeat('Casa Verde ', 4))))->toBe(trim(str_repeat('Casa Verde ', 4)).' · Manila | ReserveVision')
        ->and($title(trim(str_repeat('Casa Verde ', 7))))->toBe(trim(str_repeat('Casa Verde ', 7)).' | ReserveVision');
});

it('describes the kind, the place, the price and two amenities', function (): void {
    [$property] = listedProperty();
    $property->amenities()->attach([
        Amenity::factory()->create(['name' => 'Wi-Fi', 'slug' => 'wifi'])->id,
        Amenity::factory()->create(['name' => 'Aircon', 'slug' => 'aircon'])->id,
        Amenity::factory()->create(['name' => 'Laundry', 'slug' => 'laundry'])->id,
    ]);

    $description = (string) metaContent(listingHead()['head'], 'name', 'description');

    expect($description)->toBe('Boarding house in Barangay 395, Sampaloc, Manila. Rooms from ₱3,500 a month. Aircon and Laundry. Reserve a room or bed online.')
        ->and(mb_strlen($description))->toBeLessThanOrEqual(160);
});

it('uses absolute URLs for the canonical link, og:url and og:image', function (): void {
    [$property] = listedProperty();
    $cover = PropertyPhoto::query()->where('property_id', $property->id)->orderBy('sort')->firstOrFail();
    $head = listingHead('/listings/manila/casa-verde?move_in=2027-04-01&utm_source=x')['head'];

    $canonical = url('/listings/manila/casa-verde');
    expect($head)->toContain("<link rel=\"canonical\" href=\"{$canonical}\"")
        ->and(metaContent($head, 'property', 'og:url'))->toBe($canonical)
        ->and(metaContent($head, 'property', 'og:image'))->toBe(url("/media/photos/{$cover->path}-full.webp"))
        ->and(metaContent($head, 'property', 'og:image:width'))->toBe('1600')
        ->and(metaContent($head, 'property', 'og:image:height'))->toBe('1200')
        ->and(metaContent($head, 'property', 'og:image'))->toStartWith('http');
});

it('has the other Open Graph tags', function (): void {
    listedProperty();
    $head = listingHead()['head'];

    expect(metaContent($head, 'property', 'og:type'))->toBe('website')
        ->and(metaContent($head, 'property', 'og:locale'))->toBe('en_PH')
        ->and(metaContent($head, 'property', 'og:title'))->toBe('Casa Verde · Rooms from ₱3,500 in Barangay 395, Sampaloc, Manila | ReserveVision')
        ->and(metaContent($head, 'property', 'og:description'))->toBe(metaContent($head, 'name', 'description'))
        ->and(metaContent($head, 'name', 'twitter:card'))->toBe('summary_large_image');
});

it('leaves out og:image when there is no ready photo', function (): void {
    [$property] = listedProperty();
    PropertyPhoto::query()->where('property_id', $property->id)->delete();

    expect(listingHead()['head'])->not->toContain('og:image');
});

it('has one valid JSON-LD block with the address and an offer per room', function (): void {
    [$property, $private, $shared] = listedProperty();
    $html = listingHead()['html'];

    preg_match_all('/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s', $html, $blocks);
    expect($blocks[1])->toHaveCount(1);

    $data = json_decode(html_entity_decode($blocks[1][0]), true, flags: JSON_THROW_ON_ERROR);

    expect($data['@context'])->toBe('https://schema.org')
        ->and($data['@type'])->toBe('House')
        ->and($data['name'])->toBe('Casa Verde')
        ->and($data['url'])->toBe(url('/listings/manila/casa-verde'))
        ->and($data['address'])->toBe([
            '@type' => 'PostalAddress',
            'streetAddress' => '12 Lacson Ave',
            'addressLocality' => 'Manila',
            'addressRegion' => 'Metro Manila',
            'addressCountry' => 'PH',
        ])
        ->and($data['image'])->toHaveCount(2)
        ->and($data['image'][0])->toStartWith('http')
        ->and($data['offers'])->toHaveCount(2);

    // Cheapest first, like the page.
    expect($data['offers'][0])->toMatchArray([
        '@type' => 'Offer',
        'name' => 'Bedspace Room',
        'price' => 3500,
        'priceCurrency' => 'PHP',
    ])->and($data['offers'][0]['priceSpecification'])->toBe([
        '@type' => 'UnitPriceSpecification',
        'price' => 3500,
        'priceCurrency' => 'PHP',
        'unitText' => 'MONTH',
    ])->and($data['offers'][1]['url'])->toBe(url("/listings/manila/casa-verde#room-{$private->id}"));
});

it('uses Apartment for an apartment', function (): void {
    [$property] = listedProperty();
    $property->update(['kind' => 'apartment']);

    preg_match('/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s', listingHead()['html'], $block);

    expect(json_decode(html_entity_decode($block[1]), true)['@type'])->toBe('Apartment');
});

it('keeps a name that tries to close the script block inside it, and carries the page nonce', function (): void {
    listedProperty('Casa </script><b>x');

    $html = listingHead('/listings/manila/'.Str::slug('Casa </script><b>x'))['html'];
    preg_match('/<script type="application\/ld\+json"([^>]*)>(.*?)<\/script>/s', $html, $block);

    expect($block[2])->not->toContain('</script>')
        ->and(json_decode(html_entity_decode($block[2]), true)['name'])->toBe('Casa </script><b>x')
        ->and($block[1])->toMatch('/nonce="[A-Za-z0-9]+"/');
});
