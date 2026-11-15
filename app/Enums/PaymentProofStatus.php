<?php

declare(strict_types=1);

namespace App\Enums;

/** Mirrors the `payment_proofs.status` CHECK. */
enum PaymentProofStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
