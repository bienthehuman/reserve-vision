<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RemindAboutProof;
use App\Enums\PaymentProofStatus;
use App\Enums\ReservationStatus;
use App\Models\PaymentProof;
use App\Models\ProofReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Hourly (routes/console.php): a `proof_uploaded` reservation whose pending proof has waited over 24 hours, and again
 * over 48, emails the landlord's members, once per threshold (RESERVATIONS.md "Unanswered proofs"). A proof that is
 * already past both gets the 48-hour reminder only, not two at once. What was sent is the `proof_reminders` table, so
 * reruns and overlapping runs send nothing twice. The system never declines a paid reservation.
 */
final class RemindProofsCommand extends Command
{
    protected $signature = 'reservations:remind-proofs';

    protected $description = 'Email landlord members about proofs of payment that have waited over 24 or 48 hours';

    private const int CHUNK = 100;

    public function handle(RemindAboutProof $remind): int
    {
        $sent = 0;
        $skipped = 0;

        // Highest threshold first; a proof counts for the highest one it has reached (24 h only below 48 h).
        $thresholds = array_reverse(ProofReminder::THRESHOLDS);

        foreach ($thresholds as $index => $hours) {
            $this->due($hours, $thresholds[$index - 1] ?? null)
                ->chunkById(self::CHUNK, function (Collection $proofs) use ($remind, $hours, &$sent, &$skipped): void {
                    foreach ($proofs as $proof) {
                        $remind->handle($proof, $hours) ? $sent++ : $skipped++;
                    }
                }, 'payment_proofs.id', 'id');
        }

        $summary = "reservations:remind-proofs: {$sent} reminders queued, {$skipped} skipped";
        Log::info($summary);
        $this->info($summary);

        return self::SUCCESS;
    }

    /**
     * Pending proofs of `proof_uploaded` reservations that have waited at least `$hours` hours (and less than
     * `$below` hours, when given) and have no reminder for `$hours` yet.
     *
     * @return Builder<PaymentProof>
     */
    private function due(int $hours, ?int $below): Builder
    {
        $now = CarbonImmutable::instance(Date::now());

        return PaymentProof::query()
            ->select('payment_proofs.*')
            ->join('reservations', 'reservations.id', '=', 'payment_proofs.reservation_id')
            ->where('reservations.status', ReservationStatus::ProofUploaded)
            ->where('payment_proofs.status', PaymentProofStatus::Pending)
            ->where('payment_proofs.uploaded_at', '<=', $now->subHours($hours))
            ->when($below !== null, fn (Builder $query) => $query->where('payment_proofs.uploaded_at', '>', $now->subHours((int) $below)))
            ->whereNotExists(fn (QueryBuilder $query) => $query->selectRaw('1')->from('proof_reminders')
                ->whereColumn('proof_reminders.payment_proof_id', 'payment_proofs.id')
                ->where('proof_reminders.threshold_hours', $hours));
    }
}
