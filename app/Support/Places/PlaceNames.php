<?php

declare(strict_types=1);

namespace App\Support\Places;

/**
 * How a PSGC name becomes the name people write in an address (slice 4.4). Used by
 * `bin/psgc-convert`, so the committed files already hold the names the app shows.
 *
 * - Every name: trimmed, inner spaces collapsed, and the PSA's footnote marker
 *   dropped ("City of Lucenaa/" is "City of Lucena" with footnote "a/").
 * - A city: "City of Makati" is "Makati", and "Science City of Muñoz" and
 *   "Island Garden City of Samal" lose their titles the same way. But where the
 *   bare name would also be a province ("Cebu"), the start of one ("Davao" for
 *   Davao del Sur…), or another city or municipality ("Naga", "Samal" in Bataan),
 *   people say "Cebu City", so that is the name. Names the PSA already writes as
 *   "Quezon City" or "Pasay City" stay as they are.
 * - A city or municipality: "De" and "Del" inside a name are lowercase
 *   ("Cagayan de Oro", "San Jose del Monte", "Lope de Vega"), as people write them.
 */
final class PlaceNames
{
    /** Manila's districts (PSGC "sub-municipalities") under the name people use. */
    public const array DISTRICTS = [
        'Tondo I/II' => 'Tondo',
    ];

    public static function clean(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        // A footnote marker glued to the end: one lowercase letter or digit and a slash.
        return (string) preg_replace('/(?<=\p{L})[a-z0-9]\/$/u', '', $name);
    }

    /**
     * @param  string  $psgcName  the name as published, for example "City of San Fernando"
     * @param  list<string>  $provinces  every province name, as shown
     * @param  array<string, int>  $bareNames  how many cities and municipalities in the country go by each bare
     *                                         name (after the city title is dropped), lowercase: `bareNames()`
     */
    public static function city(string $psgcName, array $provinces, array $bareNames): string
    {
        $name = (string) preg_replace_callback('/(?<=\S) (De|Del) (?=\S)/u', fn (array $m): string => ' '.strtolower($m[1]).' ', self::clean($psgcName));
        $bare = self::bare($name);

        if ($bare === null) {
            return $name;
        }

        $key = mb_strtolower($bare);
        $clash = ($bareNames[$key] ?? 0) > 1;

        foreach ($provinces as $province) {
            $province = mb_strtolower($province);
            if ($province === $key || str_starts_with($province, $key.' ')) {
                $clash = true;
            }
        }

        return $clash ? "{$bare} City" : $bare;
    }

    public static function district(string $psgcName): string
    {
        $name = self::clean($psgcName);

        return self::DISTRICTS[$name] ?? $name;
    }

    /**
     * Counts of bare names, the `$bareNames` argument of `city()`.
     *
     * @param  list<string>  $psgcNames  every city and municipality name, as published
     * @return array<string, int>
     */
    public static function bareNames(array $psgcNames): array
    {
        $counts = [];
        foreach ($psgcNames as $psgcName) {
            $name = self::clean($psgcName);
            $key = mb_strtolower(self::bare($name) ?? $name);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /** "City of Makati" → "Makati"; null for a name without the title. */
    private static function bare(string $name): ?string
    {
        return preg_match('/^(?:Science |Island Garden )?City of (.+)$/u', $name, $match) === 1 ? $match[1] : null;
    }
}
