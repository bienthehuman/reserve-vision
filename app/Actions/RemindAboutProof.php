<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\DecidesReservations;
use App\Enums\PaymentProofStatus;
use App\Enums\ReservationStatus;
use App\Mail\Reservations\ProofReminder;
use App\Models\PaymentProof;
use App\Models\ProofReminder as ReminderRow;
use App\Models\Reservation;
use App\Support\Mail\Format;
use App\Support\Mail\Notify;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Reminds the landlord's members that a proof has waited `$hours` hours (24 or 48), once. The reservation row is
 * locked and the proof and status read again (it may have been answered a second ago); the `proof_reminders`
 * row is what makes it once: its unique key (proof, hours) refuses the second of two overlapping runs.
 * Returns whether a reminder was queued. No audit row (no one acted).
 */
final class RemindAboutProof
{
    use DecidesReservations;

    public function handle(PaymentProof $proof, int $hours): bool
    {
        try {
            $sent = DB::transaction(function () use ($proof, $hours): ?PaymentProof {
                $reservation = Reservation::query()->whereKey($proof->reservation_id)->lockForUpdate()->firstOrFail();
                $current = PaymentProof::query()->whereKey($proof->id)->lockForUpdate()->firstOrFail();

                if ($reservation->status !== ReservationStatus::ProofUploaded || $current->status !== PaymentProofStatus::Pending) {
                    return null;
                }

                ReminderRow::query()->create(['payment_proof_id' => $current->id, 'threshold_hours' => $hours, 'sent_at' => now()]);

                return $current;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        if ($sent === null) {
            return false;
        }

        Notify::landlordMembers($sent->landlord_account_id, new ProofReminder($sent->reservation_id, $hours, Format::moment($sent->uploaded_at)));

        return true;
    }
}
