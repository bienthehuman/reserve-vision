<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ExpireHold;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\BusinessRuleException;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Every minute (routes/console.php): `held` reservations whose hold has run out become `expired` and the renter is
 * emailed. A row that changed since the query (a proof uploaded a second ago) is skipped, not an error. Idempotent:
 * a second run finds nothing. Correctness never depended on it (T5, docs/RESERVATIONS.md).
 */
final class ExpireHoldsCommand extends Command
{
    protected $signature = 'reservations:expire-holds';

    protected $description = 'Mark held reservations whose 24-hour hold has run out as expired, and email the renters';

    private const int CHUNK = 100;

    public function handle(ExpireHold $expire): int
    {
        $expired = 0;
        $skipped = 0;

        Reservation::query()
            ->where('reservations.status', ReservationStatus::Held)
            ->where('reservations.hold_expires_at', '<=', Date::now())
            ->chunkById(self::CHUNK, function ($reservations) use ($expire, &$expired, &$skipped): void {
                foreach ($reservations as $reservation) {
                    try {
                        $expire->handle($reservation);
                        $expired++;
                    } catch (BusinessRuleException) {
                        $skipped++;
                    }
                }
            }, 'reservations.id', 'id');

        $summary = "reservations:expire-holds: {$expired} expired, {$skipped} skipped";
        Log::info($summary);
        $this->info($summary);

        return self::SUCCESS;
    }
}
