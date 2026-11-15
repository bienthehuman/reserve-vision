<?php

declare(strict_types=1);

namespace App\Enums;

/** Mirrors the `viewing_requests.status` CHECK. */
enum ViewingRequestStatus: string
{
    case Requested = 'requested';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
