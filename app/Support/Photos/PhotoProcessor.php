<?php

declare(strict_types=1);

namespace App\Support\Photos;

use App\Data\ProcessedPhoto;
use GdImage;
use RuntimeException;

/**
 * Everything that touches pixels (slice 4.2), on plain GD: what an upload must be,
 * and the resize into a thumbnail and a full size, both WebP.
 *
 * The type and size come from the file's CONTENT (its header), never its name or the
 * browser's MIME type. Re-encoding from decoded pixels is what strips the metadata:
 * GD keeps no EXIF, GPS, ICC or comment, so nothing of the original file survives
 * but the picture. EXIF orientation is read first and applied to the pixels.
 */
final class PhotoProcessor
{
    /** The thumbnail's width; the height follows the photo's shape. */
    public const int THUMB_WIDTH = 480;

    /** The full size's longer side. A smaller photo is never enlarged. */
    public const int FULL_LONG_SIDE = 1600;

    /** An upload's longer side must be at least this. */
    public const int MIN_LONG_SIDE = 800;

    /**
     * An upload may have at most this many pixels (about 7,300 × 5,480). GD holds 4 bytes
     * per pixel while decoding, so this is 160 MB, inside production's 256 MB memory limit.
     */
    public const int MAX_PIXELS = 40_000_000;

    public const int MAX_BYTES = 10 * 1024 * 1024;

    private const int WEBP_QUALITY = 80;

    private const array TYPES = [IMAGETYPE_JPEG => 'JPEG', IMAGETYPE_PNG => 'PNG', IMAGETYPE_WEBP => 'WebP'];

    /**
     * The type and size the file's header declares, or null when it isn't a JPEG, PNG or WebP.
     * Cheap: nothing is decoded.
     *
     * @return array{type: string, width: int, height: int}|null
     */
    public function probe(string $path): ?array
    {
        $info = @getimagesize($path);

        if ($info === false || ! isset(self::TYPES[$info[2]]) || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        return ['type' => self::TYPES[$info[2]], 'width' => $info[0], 'height' => $info[1]];
    }

    /** Whether the whole file decodes (a valid header on a truncated or renamed file doesn't). */
    public function decodes(string $path): bool
    {
        $bytes = @file_get_contents($path);

        return $bytes !== false && $this->decode($bytes) !== null;
    }

    /**
     * @throws UnreadablePhoto when the bytes don't decode
     */
    public function process(string $bytes): ProcessedPhoto
    {
        $source = $this->decode($bytes) ?? throw UnreadablePhoto::notAnImage();
        $orientation = $this->orientation($bytes);

        // Scale first, then turn: the turn works on at most 1600 px instead of the original.
        $scale = min(1, self::FULL_LONG_SIDE / max(imagesx($source), imagesy($source)));
        $full = $this->oriented($this->resized($source, $scale), $orientation);
        unset($source);

        $thumb = $this->resized($full, min(1, self::THUMB_WIDTH / imagesx($full)));

        return new ProcessedPhoto($this->webp($thumb), $this->webp($full), imagesx($full), imagesy($full));
    }

    private function decode(string $bytes): ?GdImage
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! isset(self::TYPES[$info[2]]) || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        // Palette PNGs: WebP needs true colour.
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    /** The EXIF orientation (1 to 8) of a JPEG; 1 for anything else. */
    private function orientation(string $bytes): int
    {
        if (! str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }

        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            return 1;
        }

        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);

        $orientation = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    private function resized(GdImage $image, float|int $scale): GdImage
    {
        $width = max(1, (int) round(imagesx($image) * $scale));
        $height = max(1, (int) round(imagesy($image) * $scale));

        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            throw new RuntimeException("GD could not create a {$width} × {$height} image.");
        }
        // Keep a PNG's transparency instead of turning it black.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $canvas;
    }

    /** The pixels turned and mirrored the way the camera said they should be shown. */
    private function oriented(GdImage $image, int $orientation): GdImage
    {
        // imagerotate() turns counter-clockwise.
        $degrees = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };

        if ($degrees !== 0) {
            $turned = imagerotate($image, $degrees, 0);
            if ($turned === false) {
                throw new RuntimeException('GD could not rotate the image.');
            }
            imagealphablending($turned, false);
            imagesavealpha($turned, true);
            $image = $turned;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    private function webp(GdImage $image): string
    {
        ob_start();
        $written = imagewebp($image, null, self::WEBP_QUALITY);
        $bytes = (string) ob_get_clean();

        if (! $written || $bytes === '') {
            throw new RuntimeException('GD could not encode WebP. Is PHP built with WebP support (app:doctor checks)?');
        }

        return $bytes;
    }
}
