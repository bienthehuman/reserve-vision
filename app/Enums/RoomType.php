<?php

declare(strict_types=1);

namespace App\Enums;

/** Mirrors the `rooms.room_type` CHECK. */
enum RoomType: string
{
    case Private = 'private';
    case Shared = 'shared';
}
