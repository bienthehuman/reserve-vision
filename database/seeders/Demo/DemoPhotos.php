<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use RuntimeException;

/**
 * The curated demo photos (slice 7.1): public-domain interiors and buildings kept in
 * `database/data/demo-photos/`, described in `photos.php` (what the seeder reads) and credited,
 * one row each, in `CREDITS.md`. `tests/Feature/Seeding/DemoPhotosTest.php` keeps the three in step.
 *
 * @phpstan-type DemoPhoto array{file: string, kind: string, alt: string, source: string, author: string, licence: string}
 */
final class DemoPhotos
{
    /** What a photo shows, which is what the seeder matches to a property kind and room type. */
    public const array KINDS = ['exterior', 'bunk', 'bedroom', 'apartment', 'kitchen', 'bathroom', 'laundry'];

    /** The only licences a demo photo may carry. */
    public const array LICENCES = ['CC0-1.0', 'Public Domain'];

    public static function directory(): string
    {
        return database_path('data/demo-photos');
    }

    public static function path(string $file): string
    {
        return self::directory().'/'.$file;
    }

    /** @return list<DemoPhoto> */
    public static function all(): array
    {
        /** @var list<DemoPhoto> $photos */
        $photos = require self::path('photos.php');

        return $photos;
    }

    /** @return list<DemoPhoto> */
    public static function ofKind(string $kind): array
    {
        $photos = array_values(array_filter(self::all(), fn (array $photo): bool => $photo['kind'] === $kind));

        return $photos !== [] ? $photos : throw new RuntimeException("No demo photo of kind '{$kind}' in photos.php.");
    }

    public static function bytes(string $file): string
    {
        return file_get_contents(self::path($file)) ?: throw new RuntimeException("Cannot read demo photo {$file}.");
    }
}
