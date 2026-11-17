<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\EndFinishedStay;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\BusinessRuleException;
use App\Models\Reservation;
use App\Support\BusinessDate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily at 00:05 Manila time (routes/console.php): `active` reservations whose `move_out` is today (business date)
 * or earlier become `ended`. A `confirmed` reservation whose whole stay has passed without "moved in" is left alone
 * (the no-show question is in the backlog). Idempotent.
 */
final class EndStaysCommand extends Command
{
    protected $signature = 'reservations:end-stays';

    protected $description = 'End active stays whose move-out date has come (Manila date)';

    private const int CHUNK = 100;

    public function handle(EndFinishedStay $end): int
    {
        $ended = 0;
        $skipped = 0;

        Reservation::query()
            ->where('reservations.status', ReservationStatus::Active)
            ->where('reservations.move_out', '<=', BusinessDate::today()->toDateString())
            ->chunkById(self::CHUNK, function ($reservations) use ($end, &$ended, &$skipped): void {
                foreach ($reservations as $reservation) {
                    try {
                        $end->handle($reservation);
                        $ended++;
                    } catch (BusinessRuleException) {
                        $skipped++;
                    }
                }
            }, 'reservations.id', 'id');

        $summary = "reservations:end-stays: {$ended} ended, {$skipped} skipped";
        Log::info($summary);
        $this->info($summary);

        return self::SUCCESS;
    }
}
