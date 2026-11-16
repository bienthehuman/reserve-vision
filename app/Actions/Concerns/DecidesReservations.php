<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Enums\PaymentProofStatus;
use App\Models\PaymentProof;
use App\Models\Reservation;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;

/**
 * What every landlord decision on a reservation does (slice 6.1, RESERVATIONS.md "Transition table"):
 * lock the reservation row FOR UPDATE and re-read it through the isolation scope (so only the
 * locked row's status counts, never the model the controller bound), read its proofs with locking
 * reads, and write one audit row. Callers run inside one transaction.
 *
 * @property-read AuditLogger $audit
 */
trait DecidesReservations
{
    private function lock(Reservation $reservation): Reservation
    {
        return Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * The reservation's proofs in `$status`, newest first, locked. The reservation row is already
     * locked and every proof write takes it first, so this only keeps every read a locking read.
     *
     * @return Collection<int, PaymentProof>
     */
    private function proofs(Reservation $locked, PaymentProofStatus $status): Collection
    {
        return PaymentProof::query()
            ->where('payment_proofs.reservation_id', $locked->id)
            ->where('payment_proofs.status', $status)
            ->orderByDesc('payment_proofs.uploaded_at')->orderByDesc('payment_proofs.id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Marks the pending proofs reviewed (accepted or rejected) by `$by`, and returns the fields for the audit row.
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function review(Reservation $locked, User $by, PaymentProofStatus $to, ?string $reason = null): array
    {
        $proofs = $this->proofs($locked, PaymentProofStatus::Pending);

        foreach ($proofs as $proof) {
            $proof->update([
                'status' => $to,
                'reviewed_by' => $by->id,
                'reviewed_at' => Date::now(),
                'rejection_reason' => $reason,
            ]);
        }

        if ($proofs->isEmpty()) {
            return [[], []];
        }

        return [['proof_status' => PaymentProofStatus::Pending], ['proof_status' => $to]];
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function snapshot(Reservation $reservation, array $fields): array
    {
        return array_combine($fields, array_map(fn (string $field): mixed => $reservation->getAttribute($field), $fields));
    }
}
