<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProofStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\HoldExpired;
use App\Exceptions\Domain\InvalidTransition;
use App\Models\Concerns\BelongsToLandlordAccount;
use App\Models\Scopes\LandlordAccountScope;
use App\Support\BusinessDate;
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
 * @property-read Space $space the foreign keys are required, so these are never null
 * @property-read User $renter
 */
#[Fillable([
    'space_id', 'landlord_account_id', 'renter_id', 'move_in', 'move_out', 'status', 'reservation_fee',
    'hold_expires_at', 'proof_rejections', 'decided_by', 'decided_at', 'decision_reason',
    'cancelled_by', 'cancelled_at', 'fee_refunded', 'refund_note',
])]
final class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use BelongsToLandlordAccount, HasFactory;

    /** RejectProof can send a proof back this many times; then the landlord declines (RESERVATIONS.md). */
    public const int MAX_PROOF_REJECTIONS = 2;

    /** UndoConfirmation works for this many hours after the confirmation. */
    public const int UNDO_HOURS = 24;

    /** RejectProof gives the renter a new hold of this many hours. */
    public const int HOLD_HOURS = 24;

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
     * Blocks `[$moveIn, $moveOut)` for anyone: `blocking()` and `overlapping()` composed, of every
     * account (the availability search and the public room count read it for other landlords'
     * listings). A named escape from the isolation scope; the definition of "blocking" stays in
     * the two scopes it calls, which `ReserveSpace` uses as well (docs/RESERVATIONS.md).
     *
     * @param  Builder<self>  $query
     */
    public function scopeBlockingDuring(Builder $query, CarbonImmutable $moveIn, CarbonImmutable $moveOut): void
    {
        $query->withoutGlobalScope(LandlordAccountScope::class)
            ->blocking()
            ->overlapping($moveIn, $moveOut);
    }

    /**
     * Counts towards the renter's limits: a live `held` or a `proof_uploaded`.
     * Expired holds, confirmed, active and final reservations don't.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpenForRenter(Builder $query, User $renter): void
    {
        $query->ownedByRenter($renter)
            ->where(fn (Builder $q) => $q
                ->where($q->qualifyColumn('status'), ReservationStatus::ProofUploaded)
                ->orWhere(fn (Builder $held) => self::whereLiveHold($held)));
    }

    /**
     * A live hold that runs out within the next `$hours` hours (the dashboard's "expiring soon").
     *
     * @param  Builder<self>  $query
     */
    public function scopeHoldExpiringWithin(Builder $query, int $hours): void
    {
        self::whereLiveHold($query);
        $query->where($query->qualifyColumn('hold_expires_at'), '<=', Date::now()->addHours($hours));
    }

    /**
     * `confirmed` with the move-in from today (business date) to `$days` days ahead, both inclusive.
     *
     * @param  Builder<self>  $query
     */
    public function scopeMovingInWithin(Builder $query, int $days): void
    {
        $today = BusinessDate::today();

        $query->where($query->qualifyColumn('status'), ReservationStatus::Confirmed)
            ->where($query->qualifyColumn('move_in'), '>=', $today->toDateString())
            ->where($query->qualifyColumn('move_in'), '<=', $today->addDays($days)->toDateString());
    }

    /**
     * `proof_uploaded` whose latest pending proof has waited more than `$hours` hours: past
     * the reminder threshold of RESERVATIONS.md "Unanswered proofs".
     *
     * @param  Builder<self>  $query
     */
    public function scopeProofWaitingOver(Builder $query, int $hours): void
    {
        $query->where($query->qualifyColumn('status'), ReservationStatus::ProofUploaded)
            ->whereHas('paymentProofs', fn (Builder $proofs) => $proofs
                ->where('payment_proofs.status', PaymentProofStatus::Pending)
                ->where('payment_proofs.uploaded_at', '<=', Date::now()->subHours($hours)));
    }

    /**
     * Stays under way today: `confirmed` or `active` with `move_in <= today < move_out` (business date).
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnderWayToday(Builder $query): void
    {
        $today = BusinessDate::today()->toDateString();

        $query->whereIn($query->qualifyColumn('status'), [ReservationStatus::Confirmed, ReservationStatus::Active])
            ->where($query->qualifyColumn('move_in'), '<=', $today)
            ->where($query->qualifyColumn('move_out'), '>', $today);
    }

    /**
     * The renter's own reservations, whatever the account. A named escape from
     * the isolation scope (ACCESS.md "Isolation"), for renter pages and actions.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOwnedByRenter(Builder $query, User $renter): void
    {
        $query->withoutGlobalScope(LandlordAccountScope::class)
            ->where($query->qualifyColumn('renter_id'), $renter->id);
    }

    /**
     * Reservations on spaces of listed rooms (Space::listed()), whatever the
     * account. A named escape from the isolation scope for availability counts
     * on public pages, which show only how many spaces are free, never a reservation.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnListedSpaces(Builder $query): void
    {
        $query->withoutGlobalScope(LandlordAccountScope::class)
            ->whereHas('space', fn (Builder $space) => $space->listed());
    }

    /**
     * The status to show: a `held` reservation past `hold_expires_at` is shown as `expired`
     * before the ExpireHolds job (6.3) has written it, because that is what it already is.
     */
    public function shownStatus(): ReservationStatus
    {
        return $this->status === ReservationStatus::Held && ! $this->isLiveHold() ? ReservationStatus::Expired : $this->status;
    }

    /**
     * Until when a confirmation can be undone: 24 hours after `decided_at` (exclusive), and never on or
     * after the move-in date (RESERVATIONS.md). Null when the reservation isn't confirmed.
     */
    public function undoUntil(): ?CarbonImmutable
    {
        if ($this->status !== ReservationStatus::Confirmed || $this->decided_at === null) {
            return null;
        }

        return $this->decided_at->addHours(self::UNDO_HOURS);
    }

    /** Cancelled by someone other than the renter: an owner withdrew it (WithdrawReservation, slice 6.1). */
    public function wasWithdrawn(): bool
    {
        return $this->status === ReservationStatus::Cancelled && $this->cancelled_by !== null && $this->cancelled_by !== $this->renter_id;
    }

    /** `held` and `hold_expires_at` is still in the future. */
    public function isLiveHold(): bool
    {
        return $this->status === ReservationStatus::Held
            && $this->hold_expires_at !== null
            && $this->hold_expires_at->isAfter(Date::now());
    }

    /**
     * The transition table plus the guards that depend on time: the hold's expiry, and
     * the move-in date for cancelling a confirmed stay. Every status-changing action
     * calls this on the freshly locked row before writing (UploadPaymentProof and
     * CancelReservation in 5.3, ExpireHolds in 6.3, …).
     *
     * @throws InvalidTransition when the table has no such move, or a confirmed stay has started
     * @throws HoldExpired when a renter acts on a hold that has run out (T5)
     */
    public function assertCanMoveTo(ReservationStatus $to): void
    {
        $this->status->assertCanTransitionTo($to);

        // confirmed → cancelled (CancelReservation, WithdrawReservation): only before the move-in date.
        if ($this->status === ReservationStatus::Confirmed && $to === ReservationStatus::Cancelled
            && ! BusinessDate::today()->lt($this->move_in)) {
            throw InvalidTransition::stayStarted();
        }

        // proof_uploaded → held (RejectProof): at most twice, then the landlord declines (slice 6.1).
        if ($this->status === ReservationStatus::ProofUploaded && $to === ReservationStatus::Held
            && $this->proof_rejections >= self::MAX_PROOF_REJECTIONS) {
            throw InvalidTransition::rejectionLimit();
        }

        // confirmed → proof_uploaded (UndoConfirmation): within 24 hours of the decision, and before the move-in date.
        if ($this->status === ReservationStatus::Confirmed && $to === ReservationStatus::ProofUploaded) {
            if (! BusinessDate::today()->lt($this->move_in)) {
                throw InvalidTransition::undoAfterMoveIn();
            }

            $until = $this->undoUntil();

            if ($until === null || ! $until->isAfter(Date::now())) {
                throw InvalidTransition::undoWindowClosed();
            }
        }

        // confirmed → active (MarkMovedIn): from the move-in date (business date).
        if ($this->status === ReservationStatus::Confirmed && $to === ReservationStatus::Active
            && BusinessDate::today()->lt($this->move_in)) {
            throw InvalidTransition::beforeMoveIn($this->move_in);
        }

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
