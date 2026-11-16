<?php

declare(strict_types=1);

use App\Support\Photos\PhotoProcessor;
use App\Support\Photos\UnreadablePhoto;

// Slice 4.2: the resize itself. Thumbnail 480 px wide, full size 1600 px on the long side and
// never enlarged, both WebP, nothing of the original's metadata, the right way up.

/** The chunk names of a WebP (RIFF) file: VP8, VP8L, VP8X, ALPH, EXIF, ICCP, XMP… */
function webpChunks(string $bytes): array
{
    $chunks = [];

    for ($offset = 12; $offset + 8 <= strlen($bytes);) {
        $chunks[] = trim(substr($bytes, $offset, 4));
        $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
        $offset += 8 + $size + ($size % 2);
    }

    return $chunks;
}

function photoSize(string $bytes): array
{
    $info = getimagesizefromstring($bytes);

    return [$info[0], $info[1], $info['mime']];
}

it('makes a 480 px wide thumbnail and a 1600 px full size, both WebP', function (int $width, int $height, array $full, array $thumb): void {
    $photo = (new PhotoProcessor)->process(photoBytes($width, $height));

    expect(photoSize($photo->full))->toBe([...$full, 'image/webp'])
        ->and(photoSize($photo->thumb))->toBe([...$thumb, 'image/webp'])
        ->and([$photo->width, $photo->height])->toBe($full);
})->with([
    'landscape, scaled down' => [4000, 3000, [1600, 1200], [480, 360]],
    'portrait: the long side is the height' => [3000, 4000, [1200, 1600], [480, 640]],
    'smaller than 1600: never enlarged' => [1000, 700, [1000, 700], [480, 336]],
    'exactly 1600' => [1600, 900, [1600, 900], [480, 270]],
    'narrower than the thumbnail: the thumbnail is not enlarged either' => [300, 900, [300, 900], [300, 900]],
]);

it('accepts JPEG, PNG and WebP', function (string $format): void {
    $photo = (new PhotoProcessor)->process(photoBytes(900, 600, $format));

    expect(photoSize($photo->full))->toBe([900, 600, 'image/webp']);
})->with(['jpeg', 'png', 'webp']);

it('leaves no EXIF, GPS or any other metadata in what it writes', function (): void {
    $upload = jpegWithExif(orientation: 6);

    // The upload really carries them (so the test can't pass on a file that never had any).
    $exif = exif_read_data('data://image/jpeg;base64,'.base64_encode($upload));
    expect($exif['Orientation'])->toBe(6)
        ->and($exif['GPSLatitudeRef'])->toBe('N')
        ->and($exif['GPSLatitude'])->toBe(['14/1', '35/1', '58/1']);

    $photo = (new PhotoProcessor)->process($upload);

    foreach ([$photo->thumb, $photo->full] as $webp) {
        // A plain lossy WebP is one VP8 chunk: no EXIF, XMP or ICCP chunk, and no extended header to announce one.
        expect(webpChunks($webp))->toBe(['VP8'])
            ->and($webp)->not->toContain('Exif')->not->toContain('EXIF')->not->toContain('GPS');
    }
});

it('turns the pixels the way the EXIF orientation says', function (int $orientation, array $size, string $where, array $red, array $blue): void {
    // The upload is 1200 × 800 with its LEFT half red and its right half blue.
    $photo = (new PhotoProcessor)->process(jpegWithExif($orientation));

    expect([$photo->width, $photo->height])->toBe($size, "orientation {$orientation}")
        ->and(photoColourAt($photo->full, ...$red))->toBe('red', "orientation {$orientation}: red should be {$where}")
        ->and(photoColourAt($photo->full, ...$blue))->toBe('blue')
        ->and(photoColourAt($photo->thumb, ...$red))->toBe('red');
})->with([
    '1: as stored' => [1, [1200, 800], 'on the left', [0.25, 0.5], [0.75, 0.5]],
    '2: mirrored' => [2, [1200, 800], 'on the right', [0.75, 0.5], [0.25, 0.5]],
    '3: upside down' => [3, [1200, 800], 'on the right', [0.75, 0.5], [0.25, 0.5]],
    '6: turned a quarter clockwise (a phone held upright)' => [6, [800, 1200], 'at the top', [0.5, 0.25], [0.5, 0.75]],
    '8: turned a quarter counter-clockwise' => [8, [800, 1200], 'at the bottom', [0.5, 0.75], [0.5, 0.25]],
]);

it('gives the same bytes for the same upload', function (): void {
    $upload = photoBytes(2000, 1500);
    $processor = new PhotoProcessor;

    expect($processor->process($upload)->full)->toBe($processor->process($upload)->full);
});

it('refuses what does not decode', function (string $bytes): void {
    expect(fn () => (new PhotoProcessor)->process($bytes))->toThrow(UnreadablePhoto::class);
})->with([
    'text' => ['This is not a photo.'],
    'a PDF' => ["%PDF-1.7\n1 0 obj\n<<>>\nendobj\n"],
    'a GIF (a real image, but not an accepted type)' => [fn () => photoBytes(900, 600, 'gif')],
    'a PNG header on a broken body' => [fn () => substr(photoBytes(900, 600, 'png'), 0, 40).str_repeat('x', 500)],
    'nothing' => [''],
]);

it('reads the type and size from the content and tells whether the file decodes', function (): void {
    $processor = new PhotoProcessor;
    $path = fn (string $bytes): string => tap(tempnam(sys_get_temp_dir(), 'photo'), fn (string $file) => file_put_contents($file, $bytes));

    $broken = $path(substr(photoBytes(900, 600, 'png'), 0, 40).str_repeat('x', 500));

    expect($processor->probe($path(photoBytes(900, 600, 'webp'))))->toBe(['type' => 'WebP', 'width' => 900, 'height' => 600])
        ->and($processor->probe($path('plain text')))->toBeNull()
        ->and($processor->probe($path(photoBytes(900, 600, 'gif'))))->toBeNull()
        // The header is fine, so only decoding finds it out.
        ->and($processor->probe($broken))->toBe(['type' => 'PNG', 'width' => 900, 'height' => 600])
        ->and($processor->decodes($broken))->toBeFalse()
        ->and($processor->decodes($path(photoBytes())))->toBeTrue();
});
