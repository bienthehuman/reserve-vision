<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\DecidesReservations;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\CannotRecordRefund;
use App\Models\PaymentProof;
use App\Models\Reservation;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Records whether the reservation fee was refunded (RESERVATIONS.md "Refunds"): the system never
 * moves money, it keeps the landlord's word for it. Only on a `declined` or `cancelled` reservation
 * that had a proof uploaded (otherwise nothing was paid). Can be changed again (the correction path):
 * each change is one audit row, `reservation.refund_recorded`; saving the same values writes none.
 * Who may: ReservationPolicy::recordRefund (owner, caretaker).
 */
final class RecordRefund
{
    use DecidesReservations;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotRecordRefund */
    public function handle(Reservation $reservation, bool $refunded, ?string $note): Reservation
    {
        return DB::transaction(function () use ($reservation, $refunded, $note): Reservation {
            $locked = $this->lock($reservation);

            if (! in_array($locked->status, [ReservationStatus::Declined, ReservationStatus::Cancelled], true)) {
                throw CannotRecordRefund::notEnded();
            }

            if (PaymentProof::query()->where('payment_proofs.reservation_id', $locked->id)->sharedLock()->count() === 0) {
                throw CannotRecordRefund::nothingPaid();
            }

            $before = $this->snapshot($locked, ['fee_refunded', 'refund_note']);
            $locked->update(['fee_refunded' => $refunded, 'refund_note' => $note]);

            $this->audit->record('reservation.refund_recorded', $locked, $before, $this->snapshot($locked, ['fee_refunded', 'refund_note']));

            return $locked;
        });
    }
}
