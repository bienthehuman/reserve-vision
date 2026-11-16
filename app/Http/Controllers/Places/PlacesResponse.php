<?php

declare(strict_types=1);

namespace App\Http\Controllers\Places;

use Illuminate\Http\JsonResponse;

/**
 * A places list as JSON. The lists change once a quarter at most (a new PSGC publication), and an
 * import never changes an existing id or slug, so a browser or proxy may keep one for a week.
 */
final class PlacesResponse
{
    public const int MAX_AGE = 604800;

    /** @param  list<array<string, mixed>>  $items */
    public static function of(array $items): JsonResponse
    {
        return response()->json($items)->header('Cache-Control', 'public, max-age='.self::MAX_AGE);
    }
}
