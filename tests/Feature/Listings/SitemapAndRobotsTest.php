<?php

declare(strict_types=1);

use App\Models\LandlordAccount;
use App\Models\Property;
use App\Support\Listings\SitemapCache;
use Illuminate\Support\Facades\Cache;

/** The `<loc>` values of the sitemap. */
function sitemapLocs(): array
{
    $xml = (string) test()->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
    $document = simplexml_load_string($xml);
    expect($document)->not->toBeFalse();

    return array_map(fn ($url): string => (string) $url->loc, iterator_to_array($document->url, false));
}

beforeEach(fn () => Cache::forget(SitemapCache::KEY));

it('lists the home page and exactly the published listings, with lastmod', function (): void {
    $live = Property::factory()->published()->at(placeIn('manila'))->create(['slug' => 'live-house']);
    Property::factory()->published()->at(placeIn('manila'))->create(['slug' => 'archived-house', 'archived_at' => now()]);
    Property::factory()->at(placeIn('manila'))->create(['slug' => 'draft-house']);
    $unverified = Property::factory()->published()->at(placeIn('manila'))->create(['slug' => 'unverified-house']);
    LandlordAccount::query()->whereKey($unverified->landlord_account_id)->update(['is_verified' => false, 'verified_at' => null]);

    expect(sitemapLocs())->toBe([url('/'), url('/listings/manila/live-house')]);

    $xml = (string) $this->get('/sitemap.xml')->getContent();
    expect($xml)->toContain('<lastmod>'.$live->updated_at->toAtomString().'</lastmod>');
});

it('is cached, and forgotten when a listing is published, unpublished, edited, archived or its account verified', function (): void {
    $property = Property::factory()->at(placeIn('manila'))->create(['slug' => 'later-house']);
    expect(sitemapLocs())->toBe([url('/')])
        ->and(Cache::has(SitemapCache::KEY))->toBeTrue();

    // Publishing changes it at once.
    $property->update(['is_published' => true, 'first_published_at' => now()]);
    LandlordAccount::query()->whereKey($property->landlord_account_id)->firstOrFail()->update(['is_verified' => true, 'verified_at' => now()]);
    expect(sitemapLocs())->toBe([url('/'), url('/listings/manila/later-house')]);

    // An edit moves lastmod.
    $this->travel(1)->hours();
    $property->update(['name' => 'Later House II']);
    expect((string) $this->get('/sitemap.xml')->getContent())->toContain($property->fresh()->updated_at->toAtomString());

    // Unpublishing and archiving drop it.
    $property->update(['is_published' => false]);
    expect(sitemapLocs())->toBe([url('/')]);
    $property->update(['is_published' => true]);
    expect(sitemapLocs())->toContain(url('/listings/manila/later-house'));
    $property->update(['archived_at' => now()]);
    expect(sitemapLocs())->toBe([url('/')]);

    // Unverifying the account drops it too.
    $property->update(['archived_at' => null]);
    expect(sitemapLocs())->toContain(url('/listings/manila/later-house'));
    LandlordAccount::query()->whereKey($property->landlord_account_id)->update(['is_verified' => false]);
    LandlordAccount::query()->whereKey($property->landlord_account_id)->firstOrFail()->update(['verified_at' => null]);
    expect(sitemapLocs())->toBe([url('/')]);
});

it('serves robots.txt: the sitemap and the private paths in production', function (): void {
    $this->app['env'] = 'production';

    $body = (string) $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();

    expect($body)->toContain('Sitemap: '.url('/sitemap.xml'))
        ->toContain('Disallow: /landlord')
        ->toContain('Disallow: /reservations')
        ->toContain('Disallow: /invitations')
        ->toContain('Disallow: /login')
        ->toContain('Disallow: /register')
        ->not->toMatch('/^Disallow: \/$/m');
});

it('disallows everything outside production', function (string $environment): void {
    $this->app['env'] = $environment;

    $body = (string) $this->get('/robots.txt')->assertOk()->getContent();

    expect($body)->toMatch('/^Disallow: \/$/m')->not->toContain('Sitemap:');
})->with(['local', 'testing', 'staging']);
