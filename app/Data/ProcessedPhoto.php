<?php

declare(strict_types=1);

namespace App\Data;

/** What PhotoProcessor makes of one upload: two WebP files, and the size of the full one. */
final readonly class ProcessedPhoto
{
    public function __construct(
        public string $thumb,
        public string $full,
        public int $width,
        public int $height,
    ) {}
}
