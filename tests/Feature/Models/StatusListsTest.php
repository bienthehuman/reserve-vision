<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;

it('keeps resources/js/types/reservation.ts in sync with the ReservationStatus enum', function (): void {
    $ts = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/types/reservation.ts');

    preg_match('/reservationStatuses\s*=\s*\[(.*?)\]\s*as const/s', $ts, $block);
    preg_match_all("/'([a-z_]+)'/", $block[1] ?? '', $matches);

    // Order matters too: the TS list is shown in lifecycle order.
    expect($matches[1])->toBe(array_map(fn (ReservationStatus $s): string => $s->value, ReservationStatus::cases()));
});
