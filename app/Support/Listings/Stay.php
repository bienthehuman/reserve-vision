<?php

declare(strict_types=1);

namespace App\Support\Listings;

use App\Actions\ReserveSpace;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The stay a visitor is looking at on a listing page: `?move_in=2027-04-01&months=6`. Anything
 * missing or malformed falls back to today and 6 months, so a shared link never breaks.
 */
final readonly class Stay
{
    public const int DEFAULT_MONTHS = 6;

    public function __construct(
        public CarbonImmutable $moveIn,
        public int $months,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            self::moveIn($request->query('move_in')) ?? BusinessDate::today(),
            self::months($request->query('months')),
        );
    }

    public function moveOut(): CarbonImmutable
    {
        return $this->moveIn->addMonthsNoOverflow($this->months);
    }

    private static function moveIn(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        // Round-trip, so 2027-02-30 doesn't quietly become Mar 2.
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function months(mixed $value): int
    {
        $months = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($months) && $months >= ReserveSpace::MIN_MONTHS && $months <= ReserveSpace::MAX_MONTHS
            ? $months
            : self::DEFAULT_MONTHS;
    }
}
