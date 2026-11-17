<?php

declare(strict_types=1);

use App\Support\Photos\PhotoProcessor;
use Database\Seeders\Demo\DemoPhotos;

// The curated demo photos (slice 7.1): files, photos.php and CREDITS.md must describe the same set,
// and every licence must be on the allowlist. No database needed.

/** @return array<string, array{file: string, source: string, author: string, licence: string, checked: string}> the rows of CREDITS.md by file name */
function creditRows(): array
{
    $rows = [];
    foreach (file(DemoPhotos::path('CREDITS.md'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        // | `file.jpg` | Shows | [Title](source) | Author | [Licence](link) | 2026-11-17 |
        if (preg_match('/^\| `([^`]+)` \| .+ \| \[[^\]]+\]\((https:\/\/[^)]+)\) \| (.+) \| \[([^\]]+)\]\((https:\/\/[^)]+)\) \| (\d{4}-\d{2}-\d{2}) \|$/', $line, $m)) {
            $rows[$m[1]] = ['file' => $m[1], 'source' => $m[2], 'author' => $m[3], 'licence' => $m[4], 'checked' => $m[6]];
        }
    }

    return $rows;
}

it('has one credits row per photo and one photo per credits row', function (): void {
    $listed = collect(DemoPhotos::all())->pluck('file')->all();
    $files = collect(glob(DemoPhotos::directory().'/*') ?: [])->map(fn (string $path): string => basename($path))
        ->reject(fn (string $name): bool => in_array($name, ['photos.php', 'CREDITS.md'], true))->values()->all();
    $credited = array_keys(creditRows());

    expect($listed)->not->toBeEmpty()->and(array_unique($listed))->toHaveCount(count($listed));
    expect(collect($files)->sort()->values()->all())->toBe(collect($listed)->sort()->values()->all()) // a stray file or a missing one
        ->and(collect($credited)->sort()->values()->all())->toBe(collect($listed)->sort()->values()->all());
});

it('records the same source, author and licence in photos.php and CREDITS.md, and checks every licence against the allowlist', function (): void {
    $credits = creditRows();

    foreach (DemoPhotos::all() as $photo) {
        $row = $credits[$photo['file']] ?? throw new RuntimeException("{$photo['file']} has no credits row");

        expect($photo['licence'])->toBeIn(DemoPhotos::LICENCES, "{$photo['file']}: licence")
            ->and($row['licence'])->toBe($photo['licence'], "{$photo['file']}: licence in CREDITS.md")
            ->and($row['source'])->toBe($photo['source'])
            ->and($row['author'])->toBe($photo['author'])
            ->and($photo['source'])->toStartWith('https://commons.wikimedia.org/wiki/File:')
            ->and($photo['author'])->not->toBe('')
            ->and($row['checked'])->toMatch('/^2026-\d{2}-\d{2}$/');
    }

    expect(DemoPhotos::LICENCES)->toBe(['CC0-1.0', 'Public Domain']);
});

it('describes every photo, with a known kind and an alt text of its own', function (): void {
    $photos = DemoPhotos::all();
    $alts = array_column($photos, 'alt');

    expect(array_unique($alts))->toHaveCount(count($alts)); // the seeder tests map an alt back to its photo

    foreach ($photos as $photo) {
        expect($photo['kind'])->toBeIn(DemoPhotos::KINDS)
            ->and(mb_strlen($photo['alt']))->toBeBetween(20, 125, "{$photo['file']}: alt text length");
    }

    // The seeder needs at least one of every kind (DemoPhotos::ofKind throws otherwise).
    foreach (DemoPhotos::KINDS as $kind) {
        expect(DemoPhotos::ofKind($kind))->not->toBeEmpty();
    }
    expect(count($photos))->toBeBetween(20, 30);
});

it('keeps the photos small, clean JPEGs the pipeline can read', function (): void {
    $processor = new PhotoProcessor;
    $total = 0;

    foreach (DemoPhotos::all() as $photo) {
        $path = DemoPhotos::path($photo['file']);
        $bytes = file_get_contents($path);
        $info = getimagesizefromstring((string) $bytes);
        $total += strlen((string) $bytes);

        expect($info[2] ?? null)->toBe(IMAGETYPE_JPEG, "{$photo['file']} is a JPEG")
            ->and(max($info[0], $info[1]))->toBeBetween(PhotoProcessor::MIN_LONG_SIDE, 1920, "{$photo['file']}: long side")
            ->and($processor->decodes($path))->toBeTrue()
            ->and(str_contains((string) $bytes, 'Exif'))->toBeFalse("{$photo['file']} still has metadata")
            ->and(str_contains((string) $bytes, 'http://ns.adobe.com/xap'))->toBeFalse("{$photo['file']} still has XMP");
    }

    expect($total)->toBeLessThan(12 * 1024 * 1024); // the folder's budget
});
