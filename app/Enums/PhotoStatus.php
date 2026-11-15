<?php

declare(strict_types=1);

namespace App\Enums;

/** Mirrors the `property_photos.status` CHECK. Set by the resize job. */
enum PhotoStatus: string
{
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
