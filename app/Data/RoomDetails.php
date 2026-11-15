<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\RoomType;

/** What the owner types on the room form, validated (RoomRequest). Money is integer centavos. Input to CreateRoom and UpdateRoom. */
final readonly class RoomDetails
{
    public function __construct(
        public string $name,
        public RoomType $type,
        public int $monthlyRate,
        public int $reservationFee,
        public int $depositMonths,
        public int $advanceMonths,
        /** Shared rooms: how many beds to create (create) or to end up with when switching from private (update). */
        public ?int $beds,
    ) {}
}
