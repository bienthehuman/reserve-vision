<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use GdImage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Paints the demo listings' photos (slice 4.2): six simple room-like scenes in four colourways
 * of the design system's palette, drawn with GD. No downloaded or third-party image is involved,
 * so there is no licence to keep track of. Nothing here is random: a scene and a colourway always
 * give the same pixels. The seeder runs each painting through the same resize code as an upload.
 */
final class DemoPhotoPainter
{
    public const int WIDTH = 1600;

    public const int HEIGHT = 1200;

    /** Scene => what the photo shows, for its `alt` text (":property" is replaced). */
    public const array SCENES = [
        'front' => 'Front of :property',
        'bedroom' => 'Bedroom with a single bed and a window, :property',
        'bunks' => 'Bunk beds in a shared room, :property',
        'kitchen' => 'Shared kitchen and dining table, :property',
        'bathroom' => 'Bathroom with a sink and a mirror, :property',
        'desk' => 'Study corner with a desk and shelves, :property',
    ];

    /** Colourways: wall, accent, second accent. Tokens from resources/scss/_tokens.scss. */
    public const array PALETTES = [
        'ube' => ['wall' => 0xF1ECF8, 'accent' => 0x5B3E96, 'second' => 0xF7C948],
        'mango' => ['wall' => 0xFEF6DA, 'accent' => 0xF7C948, 'second' => 0x5B3E96],
        'sky' => ['wall' => 0xE8F0FA, 'accent' => 0x1D5FA8, 'second' => 0xF7C948],
        'leaf' => ['wall' => 0xE7F4EC, 'accent' => 0x1E7B45, 'second' => 0x5B3E96],
    ];

    private const int INK = 0x1F2A2E;

    private const int MUTED = 0x5B6770;

    private const int BORDER = 0xD9DEE1;

    private const int WHITE = 0xFFFFFF;

    private const int WOOD = 0xC9A27A;

    private const int WOOD_DARK = 0x9C7650;

    private const int FLOOR = 0xE4D5C3;

    private GdImage $image;

    /** @var array{wall: int, accent: int, second: int} */
    private array $palette;

    /** A 1600 × 1200 JPEG. */
    public function paint(string $scene, string $palette): string
    {
        if (! isset(self::SCENES[$scene], self::PALETTES[$palette])) {
            throw new InvalidArgumentException("Unknown demo photo [{$scene}, {$palette}].");
        }

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($image === false) {
            throw new RuntimeException('GD could not create an image.');
        }
        imageantialias($image, true);
        $this->image = $image;
        $this->palette = self::PALETTES[$palette];

        match ($scene) {
            'front' => $this->front(),
            'bedroom' => $this->bedroom(),
            'bunks' => $this->bunks(),
            'kitchen' => $this->kitchen(),
            'bathroom' => $this->bathroom(),
            default => $this->desk(),
        };

        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    // ------------------------------------------------------------------ scenes

    private function front(): void
    {
        $this->gradient(0, 0, self::WIDTH, 820, 0xBFD9F2, 0xF4F8FC);
        $this->ellipse(1320, 190, 130, 130, self::mix(0xF7C948, self::WHITE, 0.25));
        $this->ellipse(330, 240, 420, 110, self::WHITE, 40);
        $this->ellipse(520, 200, 300, 100, self::WHITE, 40);
        $this->gradient(0, 820, self::WIDTH, self::HEIGHT, 0xC9CED2, 0xAEB5BA);

        // The building, its roof and a 4 × 3 grid of windows.
        $this->rect(330, 250, 1270, 900, $this->palette['wall']);
        $this->rect(330, 250, 1270, 262, self::mix($this->palette['wall'], self::INK, 0.15));
        $this->rect(300, 200, 1300, 252, $this->palette['accent']);
        foreach ([330, 480, 630] as $row => $top) {
            foreach ([400, 610, 820, 1030] as $column => $left) {
                if ($row === 2 && $column === 1) {
                    continue; // the door
                }
                $this->window($left, $top, 150, 110, lit: ($row + $column) % 3 === 0);
            }
        }
        $this->rect(600, 640, 770, 900, $this->palette['accent']);
        $this->rect(618, 660, 752, 780, self::mix($this->palette['accent'], self::WHITE, 0.75));
        $this->ellipse(745, 800, 16, 16, $this->palette['second']);
        $this->rect(560, 900, 810, 925, self::BORDER);

        // A tree and a low hedge.
        $this->rect(1395, 640, 1425, 905, self::WOOD_DARK);
        $this->ellipse(1410, 560, 260, 300, 0x3F8F5B);
        $this->ellipse(1460, 630, 180, 190, 0x57A873);
        $this->ellipse(190, 880, 300, 120, 0x57A873);
    }

    private function bedroom(): void
    {
        $this->room();
        $this->window(980, 180, 420, 420, lit: true);
        $this->rect(940, 150, 965, 660, $this->palette['accent']);
        $this->rect(1415, 150, 1440, 660, $this->palette['accent']);

        // Bed: headboard, mattress, pillow, blanket.
        $this->shadow(480, 1010, 900, 90);
        $this->rect(110, 470, 150, 960, self::WOOD_DARK);
        $this->rect(150, 700, 900, 960, self::WOOD);
        $this->rect(150, 640, 900, 800, self::WHITE);
        $this->roundRect(190, 590, 420, 700, 26, self::mix($this->palette['wall'], self::WHITE, 0.4));
        $this->rect(450, 640, 900, 830, $this->palette['accent']);
        $this->rect(450, 640, 900, 672, self::mix($this->palette['accent'], self::WHITE, 0.35));

        // Bedside table and lamp.
        $this->rect(960, 760, 1150, 960, self::WOOD);
        $this->rect(960, 760, 1150, 780, self::WOOD_DARK);
        $this->rect(1047, 650, 1063, 760, self::MUTED);
        imagefilledpolygon($this->image, [990, 650, 1120, 650, 1090, 560, 1020, 560], $this->color($this->palette['second']));

        $this->frame(330, 230, 250, 190);
    }

    private function bunks(): void
    {
        $this->room();
        $this->window(1120, 200, 320, 360, lit: false);

        foreach ([250, 800] as $left) {
            $this->shadow($left + 210, 1015, 520, 70);
            // Posts, two beds, a ladder.
            $this->rect($left, 250, $left + 28, 990, self::WOOD_DARK);
            $this->rect($left + 392, 250, $left + 420, 990, self::WOOD_DARK);
            foreach ([430, 800] as $bed => $top) {
                $this->rect($left, $top, $left + 420, $top + 46, self::WOOD);
                $this->rect($left + 28, $top - 60, $left + 392, $top, self::WHITE);
                $this->rect($left + 150, $top - 60, $left + 392, $top, $bed === 0 ? $this->palette['accent'] : $this->palette['second']);
                $this->roundRect($left + 44, $top - 92, $left + 140, $top - 44, 14, self::mix($this->palette['wall'], self::WHITE, 0.5));
            }
            $this->rect($left + 300, 476, $left + 312, 990, self::WOOD_DARK);
            $this->rect($left + 368, 476, $left + 380, 990, self::WOOD_DARK);
            foreach ([560, 650, 740, 890] as $rung) {
                $this->rect($left + 300, $rung, $left + 380, $rung + 12, self::WOOD_DARK);
            }
        }

        // Lockers between the window and the floor.
        $this->rect(1280, 640, 1500, 960, self::mix($this->palette['accent'], self::WHITE, 0.55));
        $this->rect(1388, 640, 1392, 960, self::MUTED);
        $this->ellipse(1370, 800, 12, 12, self::INK);
        $this->ellipse(1410, 800, 12, 12, self::INK);
    }

    private function kitchen(): void
    {
        $this->room();
        $this->window(120, 190, 360, 300, lit: true);

        // Wall cabinets, backsplash, counter with a sink and a stove.
        $this->rect(640, 160, 1500, 380, self::mix($this->palette['accent'], self::WHITE, 0.2));
        foreach ([855, 1070, 1285] as $seam) {
            $this->rect($seam, 160, $seam + 4, 380, self::mix($this->palette['accent'], self::INK, 0.25));
        }
        $this->rect(640, 500, 1500, 640, self::WHITE);
        for ($x = 640; $x < 1500; $x += 86) {
            $this->rect($x, 500, $x + 2, 640, self::BORDER);
        }
        $this->rect(640, 568, 1500, 570, self::BORDER);
        $this->rect(620, 640, 1520, 676, self::INK);
        $this->rect(640, 676, 1500, 960, self::mix($this->palette['accent'], self::WHITE, 0.2));
        foreach ([855, 1070, 1285] as $seam) {
            $this->rect($seam, 676, $seam + 4, 960, self::mix($this->palette['accent'], self::INK, 0.25));
        }
        $this->rect(700, 628, 850, 640, self::MUTED);
        $this->rect(770, 560, 782, 630, self::MUTED);
        $this->ellipse(1180, 634, 120, 18, self::MUTED);
        $this->ellipse(1360, 634, 120, 18, self::MUTED);
        $this->roundRect(1310, 560, 1410, 628, 12, $this->palette['second']);

        // Dining table and two stools.
        $this->shadow(330, 1090, 620, 80);
        $this->rect(80, 800, 580, 836, self::WOOD);
        $this->rect(110, 836, 134, 1080, self::WOOD_DARK);
        $this->rect(526, 836, 550, 1080, self::WOOD_DARK);
        foreach ([190, 400] as $left) {
            $this->rect($left, 920, $left + 120, 944, $this->palette['second']);
            $this->rect($left + 14, 944, $left + 26, 1085, self::INK);
            $this->rect($left + 94, 944, $left + 106, 1085, self::INK);
        }
        $this->ellipse(330, 780, 90, 44, self::WHITE);
    }

    private function bathroom(): void
    {
        // Tiled wall and floor.
        $this->gradient(0, 0, self::WIDTH, 880, self::mix($this->palette['wall'], self::WHITE, 0.3), $this->palette['wall']);
        for ($x = 0; $x < self::WIDTH; $x += 160) {
            $this->rect($x, 0, $x + 3, 880, self::WHITE);
        }
        for ($y = 0; $y < 880; $y += 160) {
            $this->rect(0, $y, self::WIDTH, $y + 3, self::WHITE);
        }
        $this->rect(0, 560, self::WIDTH, 600, $this->palette['accent']);
        $this->gradient(0, 880, self::WIDTH, self::HEIGHT, 0xC9CED2, 0xAEB5BA);

        // Mirror, sink on a cabinet, tap.
        $this->roundRect(250, 150, 650, 520, 30, self::BORDER);
        $this->gradient(270, 170, 630, 500, 0xEAF3FB, 0xCFE0F0);
        $this->shadow(450, 1010, 520, 70);
        $this->rect(230, 720, 670, 1000, self::mix($this->palette['accent'], self::WHITE, 0.25));
        $this->rect(448, 720, 452, 1000, self::mix($this->palette['accent'], self::INK, 0.25));
        $this->roundRect(200, 650, 700, 730, 24, self::WHITE);
        $this->ellipse(450, 672, 300, 30, self::BORDER);
        $this->rect(442, 590, 458, 655, self::MUTED);
        $this->rect(442, 590, 510, 604, self::MUTED);

        // Shower curtain on a rail, a towel on a hook.
        $this->rect(880, 120, 1600, 132, self::MUTED);
        for ($x = 940; $x < 1600; $x += 110) {
            $this->gradient($x, 132, $x + 110, 960, self::mix($this->palette['second'], self::WHITE, 0.55), self::mix($this->palette['second'], self::WHITE, 0.3));
            $this->rect($x, 132, $x + 3, 960, self::mix($this->palette['second'], self::INK, 0.15));
        }
        $this->ellipse(790, 330, 20, 20, self::MUTED);
        $this->roundRect(750, 335, 830, 560, 10, self::WHITE);
        $this->rect(750, 500, 830, 520, $this->palette['accent']);
    }

    private function desk(): void
    {
        $this->room();
        $this->window(160, 170, 400, 380, lit: true);

        // Shelves with books.
        foreach ([240, 400] as $shelf) {
            $this->rect(820, $shelf + 100, 1440, $shelf + 118, self::WOOD_DARK);
            $x = 850;
            foreach ([0, 1, 2, 0, 1, 2, 1] as $index => $tone) {
                $height = 64 + ($index * 17 + $shelf) % 34;
                $color = [$this->palette['accent'], $this->palette['second'], self::MUTED][$tone];
                $this->rect($x, $shelf + 100 - $height, $x + 34, $shelf + 100, $color);
                $x += 42;
            }
            $this->ellipse(1340, $shelf + 66, 70, 70, self::mix($this->palette['accent'], self::WHITE, 0.6));
        }

        // Desk, lamp, laptop, chair.
        $this->shadow(1010, 1050, 800, 80);
        $this->rect(700, 700, 1480, 736, self::WOOD);
        $this->rect(730, 736, 756, 1040, self::WOOD_DARK);
        $this->rect(1424, 736, 1450, 1040, self::WOOD_DARK);
        $this->rect(1180, 736, 1450, 900, self::WOOD);
        $this->ellipse(1315, 818, 18, 18, self::WOOD_DARK);
        $this->rect(880, 600, 1090, 696, self::INK);
        $this->rect(892, 610, 1078, 686, 0xCFE0F0);
        $this->rect(850, 694, 1120, 702, self::MUTED);
        $this->rect(1330, 560, 1342, 700, self::MUTED);
        imagefilledpolygon($this->image, [1280, 560, 1392, 560, 1366, 490, 1306, 490], $this->color($this->palette['second']));
        $this->roundRect(880, 800, 1080, 840, 14, $this->palette['accent']);
        $this->roundRect(900, 620, 1060, 800, 18, self::mix($this->palette['accent'], self::WHITE, 0.15));
        $this->rect(972, 840, 988, 1000, self::INK);
        $this->rect(900, 1000, 1060, 1016, self::INK);
    }

    // --------------------------------------------------------------- furniture

    /** A wall in the colourway, a skirting board and a wooden floor with boards. */
    private function room(): void
    {
        $this->gradient(0, 0, self::WIDTH, 940, self::mix($this->palette['wall'], self::WHITE, 0.35), $this->palette['wall']);
        $this->rect(0, 940, self::WIDTH, 962, self::WHITE);
        $this->gradient(0, 962, self::WIDTH, self::HEIGHT, self::FLOOR, self::mix(self::FLOOR, self::WOOD, 0.45));
        for ($x = -200; $x < self::WIDTH + 200; $x += 230) {
            imageline($this->image, $x + 100, 962, $x, self::HEIGHT, $this->color(self::mix(self::FLOOR, self::WOOD_DARK, 0.3)));
        }
    }

    private function window(int $left, int $top, int $width, int $height, bool $lit): void
    {
        $this->rect($left - 14, $top - 14, $left + $width + 14, $top + $height + 14, self::WHITE);
        $lit
            ? $this->gradient($left, $top, $left + $width, $top + $height, 0xFDF3C8, 0xDCEBF8)
            : $this->gradient($left, $top, $left + $width, $top + $height, 0xBFD9F2, 0xE6F0FA);
        $this->rect($left + intdiv($width, 2) - 5, $top, $left + intdiv($width, 2) + 5, $top + $height, self::WHITE);
        $this->rect($left, $top + intdiv($height, 2) - 5, $left + $width, $top + intdiv($height, 2) + 5, self::WHITE);
    }

    /** A framed picture: a little landscape in the colourway. */
    private function frame(int $left, int $top, int $width, int $height): void
    {
        $this->rect($left - 12, $top - 12, $left + $width + 12, $top + $height + 12, self::INK);
        $this->gradient($left, $top, $left + $width, $top + $height, self::WHITE, self::mix($this->palette['second'], self::WHITE, 0.5));
        $this->ellipse($left + intdiv($width, 2), $top + intdiv($height, 2), intdiv($height, 2), intdiv($height, 2), $this->palette['accent']);
    }

    // ---------------------------------------------------------------- drawing

    private function color(int $rgb, int $alpha = 0): int
    {
        return (int) imagecolorallocatealpha($this->image, ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF, max(0, min(127, $alpha)));
    }

    private static function mix(int $from, int $to, float $amount): int
    {
        $channel = fn (int $shift): int => (int) round((($from >> $shift) & 0xFF) * (1 - $amount) + (($to >> $shift) & 0xFF) * $amount);

        return ($channel(16) << 16) | ($channel(8) << 8) | $channel(0);
    }

    private function rect(int $left, int $top, int $right, int $bottom, int $rgb): void
    {
        imagefilledrectangle($this->image, $left, $top, $right, $bottom, $this->color($rgb));
    }

    private function roundRect(int $left, int $top, int $right, int $bottom, int $radius, int $rgb): void
    {
        $color = $this->color($rgb);
        imagefilledrectangle($this->image, $left + $radius, $top, $right - $radius, $bottom, $color);
        imagefilledrectangle($this->image, $left, $top + $radius, $right, $bottom - $radius, $color);
        foreach ([[$left + $radius, $top + $radius], [$right - $radius, $top + $radius], [$left + $radius, $bottom - $radius], [$right - $radius, $bottom - $radius]] as [$x, $y]) {
            imagefilledellipse($this->image, $x, $y, $radius * 2, $radius * 2, $color);
        }
    }

    private function ellipse(int $x, int $y, int $width, int $height, int $rgb, int $alpha = 0): void
    {
        imagefilledellipse($this->image, $x, $y, $width, $height, $this->color($rgb, $alpha));
    }

    /** A soft shadow on the floor under a piece of furniture. */
    private function shadow(int $x, int $y, int $width, int $height): void
    {
        foreach ([1.0, 0.8, 0.6] as $step) {
            $this->ellipse($x, $y, (int) ($width * $step), (int) ($height * $step), self::INK, 118);
        }
    }

    /** A soft top-to-bottom gradient. */
    private function gradient(int $left, int $top, int $right, int $bottom, int $from, int $to): void
    {
        $rows = max(1, $bottom - $top);

        for ($y = 0; $y < $rows; $y++) {
            imageline($this->image, $left, $top + $y, $right, $top + $y, $this->color(self::mix($from, $to, $y / $rows)));
        }
    }
}
