<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Status changes go through App\Actions and ReservationStatus::assertCanTransitionTo()
 * (tests/Arch/ReservationWritesTest.php). `move_out` is exclusive.
 *
 * @property int $id
 * @property int $space_id
 * @property int $landlord_account_id
 * @property int $renter_id
 * @property CarbonImmutable $move_in
 * @property CarbonImmutable $move_out
 * @property ReservationStatus $status
 * @property int $reservation_fee
 * @property CarbonImmutable|null $hold_expires_at
 * @property int $proof_rejections
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_reason
 * @property int|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property bool|null $fee_refunded
 * @property string|null $refund_note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'space_id', 'landlord_account_id', 'renter_id', 'move_in', 'move_out', 'status', 'reservation_fee',
    'hold_expires_at', 'proof_rejections', 'decided_by', 'decided_at', 'decision_reason',
    'cancelled_by', 'cancelled_at', 'fee_refunded', 'refund_note',
])]
final class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'move_in' => 'immutable_date',
            'move_out' => 'immutable_date',
            'status' => ReservationStatus::class,
            'reservation_fee' => 'integer',
            'hold_expires_at' => 'immutable_datetime',
            'proof_rejections' => 'integer',
            'decided_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'fee_refunded' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Space, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function renter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'renter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return HasMany<PaymentProof, $this>
     */
    public function paymentProofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class);
    }
}
