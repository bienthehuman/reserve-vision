<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Exceptions\Domain\HoldExpired;
use App\Exceptions\Domain\InvalidTransition;
use Carbon\CarbonImmutable;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Date;

/**
 * Status changes go through App\Actions and assertCanMoveTo()
 * (tests/Arch/ReservationWritesTest.php). `move_out` is exclusive.
 *
 * The scopes below are the one definition of "blocking", "overlapping" and
 * "open" (docs/RESERVATIONS.md "Vocabulary"). They are plain query-builder
 * conditions on qualified `reservations.*` columns, with "now" bound from
 * Laravel's clock (never SQL NOW()), so the availability search can apply them
 * to a joined or sub-query too.
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

    /**
     * Makes its space unavailable for overlapping dates: `proof_uploaded`,
     * `confirmed` or `active`, or `held` with the hold still live. An expired
     * hold stops blocking at `hold_expires_at`, whether or not ExpireHolds has run.
     *
     * @param  Builder<self>  $query
     */
    public function scopeBlocking(Builder $query): void
    {
        $blocking = array_values(array_filter(ReservationStatus::cases(), fn (ReservationStatus $s): bool => $s->isBlockingStatus()));

        $query->where(fn (Builder $q) => $q
            ->whereIn($q->qualifyColumn('status'), $blocking)
            ->orWhere(fn (Builder $held) => self::whereLiveHold($held)));
    }

    /**
     * The stay overlaps `[$moveIn, $moveOut)`. `move_out` is exclusive on both
     * sides, so a stay ending Jun 1 doesn't overlap one starting Jun 1.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonImmutable $moveIn, CarbonImmutable $moveOut): void
    {
        $query->where($query->qualifyColumn('move_in'), '<', $moveOut->toDateString())
            ->where($query->qualifyColumn('move_out'), '>', $moveIn->toDateString());
    }

    /**
     * Counts towards the renter's limits: a live `held` or a `proof_uploaded`.
     * Expired holds, confirmed, active and final reservations don't.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpenForRenter(Builder $query, User $renter): void
    {
        $query->where($query->qualifyColumn('renter_id'), $renter->id)
            ->where(fn (Builder $q) => $q
                ->where($q->qualifyColumn('status'), ReservationStatus::ProofUploaded)
                ->orWhere(fn (Builder $held) => self::whereLiveHold($held)));
    }

    /** `held` and `hold_expires_at` is still in the future. */
    public function isLiveHold(): bool
    {
        return $this->status === ReservationStatus::Held
            && $this->hold_expires_at !== null
            && $this->hold_expires_at->isAfter(Date::now());
    }

    /**
     * The transition table plus the guards that depend on the hold's expiry.
     * Every status-changing action calls this on the freshly locked row before
     * writing (UploadPaymentProof in 5.3, ExpireHolds in 6.3, …).
     *
     * @throws InvalidTransition when the table has no such move
     * @throws HoldExpired when a renter acts on a hold that has run out (T5)
     */
    public function assertCanMoveTo(ReservationStatus $to): void
    {
        $this->status->assertCanTransitionTo($to);

        if ($this->status !== ReservationStatus::Held) {
            return;
        }

        if ($to === ReservationStatus::ProofUploaded && ! $this->isLiveHold()) {
            throw HoldExpired::make();
        }

        if ($to === ReservationStatus::Expired && $this->isLiveHold()) {
            throw InvalidTransition::holdStillLive();
        }
    }

    /**
     * @param  Builder<self>  $query
     */
    private static function whereLiveHold(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), ReservationStatus::Held)
            ->where($query->qualifyColumn('hold_expires_at'), '>', Date::now());
    }
}
