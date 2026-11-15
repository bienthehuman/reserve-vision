<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Closure;

/**
 * Lays blocking stays end to end on one space, so two of them can never
 * overlap by construction: each stay starts on or after the previous one's
 * `move_out` (exclusive, so the same day is allowed). The demo data does not go
 * through `ReserveSpace` (slice 2.3), so this is what keeps its invariant;
 * the self-check test verifies the result by brute force.
 */
final class StayScheduler
{
    /** Lease lengths in months; repeats make the common ones likelier. */
    private const MONTHS = [1, 2, 3, 3, 4, 6, 6, 6, 12, 12];

    /**
     * @param  Closure(int, int): int  $between  Seeded inclusive random integer.
     */
    public function __construct(private readonly Closure $between) {}

    /**
     * @return list<array{move_in: CarbonImmutable, move_out: CarbonImmutable}>
     */
    public function chain(CarbonImmutable $from, CarbonImmutable $lastMoveIn): array
    {
        $stays = [];
        $cursor = $from->addDays(($this->between)(0, 120));

        while (true) {
            $gap = ($this->between)(1, 10) <= 4 ? 0 : ($this->between)(1, 45);
            $moveIn = $cursor->addDays($gap);

            if ($moveIn > $lastMoveIn) {
                return $stays;
            }

            $months = self::MONTHS[($this->between)(0, count(self::MONTHS) - 1)];
            $moveOut = $moveIn->addMonthsNoOverflow($months);
            $stays[] = ['move_in' => $moveIn, 'move_out' => $moveOut];
            $cursor = $moveOut;
        }
    }

    /** Whether two half-open stays `[in, out)` (as `Y-m-d` strings) overlap. */
    public static function overlaps(string $aIn, string $aOut, string $bIn, string $bOut): bool
    {
        return $aIn < $bOut && $bIn < $aOut;
    }
}
