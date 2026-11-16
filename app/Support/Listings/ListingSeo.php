<?php

declare(strict_types=1);

namespace App\Support\Listings;

use App\Enums\PropertyKind;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Support\Pesos;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;

/**
 * Everything a listing page puts in its <head> for search engines and link previews:
 * the title, the description, the canonical and Open Graph URLs (absolute) and the
 * schema.org JSON-LD. Built on the server, so the SSR HTML has it and a test can read it.
 */
final class ListingSeo
{
    /** Search results cut a title at about this many characters. */
    public const int TITLE_LENGTH = 80;

    public const int DESCRIPTION_LENGTH = 160;

    /**
     * @param  list<array{id: int, name: string, monthly_rate: int}>  $rooms
     * @param  list<string>  $amenities
     * @param  list<PropertyPhoto>  $photos  the shown photos, cover first
     * @return array{title: string, description: string, canonical: string, image: array{url: string, width: int, height: int, alt: string}|null, json_ld: string, nonce: string}
     */
    public static function for(Property $property, array $rooms, array $amenities, array $photos): array
    {
        $canonical = url(ListingCards::url($property));
        $from = $rooms === [] ? null : min(array_column($rooms, 'monthly_rate'));
        $cover = $photos[0] ?? null;

        return [
            'title' => self::title($property, $from),
            'description' => self::description($property, $from, $amenities),
            'canonical' => $canonical,
            'image' => $cover === null || $cover->full_url === null ? null : [
                'url' => url($cover->full_url),
                'width' => $cover->width ?? 0,
                'height' => $cover->height ?? 0,
                'alt' => $cover->altText($property->name),
            ],
            'json_ld' => self::jsonLd($property, $canonical, $rooms, $photos),
            // The JSON-LD block carries the page's CSP nonce, like every other tag in the head that could be mistaken for a script.
            'nonce' => Vite::cspNonce() ?? '',
        ];
    }

    public static function kindLabel(PropertyKind $kind): string
    {
        return $kind === PropertyKind::Apartment ? self::t('Apartment') : self::t('Boarding house');
    }

    /**
     * `__()` is typed `array|string`: a sentence here is always a string.
     *
     * @param  array<string, string>  $replace
     */
    private static function t(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }

    /** "Casa Verde · Rooms from ₱4,500 in Sampaloc, Manila | ReserveVision", shortened a part at a time when it runs long. */
    private static function title(Property $property, ?int $from): string
    {
        $app = config()->string('app.name');
        $price = $from === null ? null : self::t('Rooms from :price', ['price' => Pesos::format($from)]);
        $candidates = [
            [$price, "{$property->barangay}, {$property->city}"],
            [$price, $property->city],
            [null, $property->city],
        ];

        foreach ($candidates as [$priceText, $place]) {
            $middle = $priceText === null ? $place : "{$priceText} in {$place}";
            $title = "{$property->name} · {$middle} | {$app}";

            if (mb_strlen($title) <= self::TITLE_LENGTH) {
                return $title;
            }
        }

        return "{$property->name} | {$app}";
    }

    /**
     * @param  list<string>  $amenities
     */
    private static function description(Property $property, ?int $from, array $amenities): string
    {
        $parts = [self::t(':kind in :barangay, :city.', [
            'kind' => self::kindLabel($property->kind),
            'barangay' => $property->barangay,
            'city' => $property->city,
        ])];

        if ($from !== null) {
            $parts[] = self::t('Rooms from :price a month.', ['price' => Pesos::format($from)]);
        }

        $two = array_slice($amenities, 0, 2);
        if ($two !== []) {
            $parts[] = self::t(':list.', ['list' => implode(' and ', $two)]);
        }

        $parts[] = self::t('Reserve a room or bed online.');

        return Str::limit(implode(' ', $parts), self::DESCRIPTION_LENGTH, '…');
    }

    /**
     * @param  list<array{id: int, name: string, monthly_rate: int}>  $rooms
     * @param  list<PropertyPhoto>  $photos
     */
    private static function jsonLd(Property $property, string $canonical, array $rooms, array $photos): string
    {
        $images = [];
        foreach (array_slice($photos, 0, 6) as $photo) {
            if ($photo->full_url !== null) {
                $images[] = url($photo->full_url);
            }
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => $property->kind === PropertyKind::Apartment ? 'Apartment' : 'House',
            'name' => $property->name,
            'url' => $canonical,
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $property->address,
                'addressLocality' => $property->city,
                'addressRegion' => $property->province,
                'addressCountry' => 'PH',
            ]),
            'image' => $images,
            'offers' => array_map(fn (array $room): array => [
                '@type' => 'Offer',
                'name' => $room['name'],
                'url' => "{$canonical}#room-{$room['id']}",
                'price' => $room['monthly_rate'] / 100,
                'priceCurrency' => 'PHP',
                'priceSpecification' => [
                    '@type' => 'UnitPriceSpecification',
                    'price' => $room['monthly_rate'] / 100,
                    'priceCurrency' => 'PHP',
                    'unitText' => 'MONTH',
                ],
            ], $rooms),
        ];

        if ($images === []) {
            unset($data['image']);
        }

        // HEX_TAG and HEX_AMP: a name containing "</script>" can't end the block.
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
