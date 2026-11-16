<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use GdImage;
use RuntimeException;

/**
 * Paints the demo payment proofs (slice 5.3): a plain e-wallet style receipt, drawn with GD's
 * built-in font, clearly marked as a demo. Nothing random: a number always gives the same pixels.
 */
final class DemoReceiptPainter
{
    public const int WIDTH = 480;

    public const int HEIGHT = 720;

    /** A 480 × 720 JPEG for placeholder `$number` (1 to 9). */
    public function paint(int $number): string
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        if ($image === false) {
            throw new RuntimeException('GD could not create the receipt image.');
        }

        $white = $this->colour($image, 0xFFFFFF);
        $ink = $this->colour($image, 0x1F2A2E);
        $muted = $this->colour($image, 0x5B6770);
        $ube = $this->colour($image, 0x5B3E96);
        $border = $this->colour($image, 0xD9DEE1);

        imagefilledrectangle($image, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $white);
        imagefilledrectangle($image, 0, 0, self::WIDTH - 1, 110, $ube);
        imagestring($image, 5, 24, 30, 'DEMO RECEIPT', $white);
        imagestring($image, 3, 24, 60, 'Not a real payment', $white);

        $lines = [
            ['Sent to', 'Landlord (demo)'],
            ['Purpose', 'Reservation fee'],
            ['Reference', sprintf('DEMO-%04d', $number * 1111)],
            ['Receipt', "Placeholder {$number} of 9"],
        ];

        $y = 150;

        foreach ($lines as [$label, $value]) {
            imagestring($image, 3, 24, $y, $label, $muted);
            imagestring($image, 5, 24, $y + 20, $value, $ink);
            imageline($image, 24, $y + 50, self::WIDTH - 24, $y + 50, $border);
            $y += 80;
        }

        imagestring($image, 2, 24, self::HEIGHT - 40, 'ReserveVision demo data', $muted);

        ob_start();
        imagejpeg($image, null, 80);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }

    private function colour(GdImage $image, int $rgb): int
    {
        $colour = imagecolorallocate($image, ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);

        return $colour === false ? throw new RuntimeException('GD could not allocate a colour.') : $colour;
    }
}
