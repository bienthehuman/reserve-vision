<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ViewingRequestStatus;
use App\Exceptions\Domain\BusinessRuleException;
use App\Exceptions\Domain\InvalidViewingMove;
use App\Models\Concerns\BelongsToLandlordAccount;
use Carbon\CarbonImmutable;
use Database\Factories\ViewingRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;

/**
 * @property int $id
 * @property int $property_id
 * @property int $landlord_account_id
 * @property int $renter_id
 * @property CarbonImmutable $preferred_at
 * @property string|null $message
 * @property ViewingRequestStatus $status
 * @property string|null $landlord_note
 * @property int|null $decided_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Property $property
 * @property-read User $renter
 */
#[Fillable(['property_id', 'landlord_account_id', 'renter_id', 'preferred_at', 'message', 'status', 'landlord_note', 'decided_by'])]
final class ViewingRequest extends Model
{
    /** @use HasFactory<ViewingRequestFactory> */
    use BelongsToLandlordAccount, HasFactory;

    /** Open requests one renter can have at once, and in one property (docs/ACCESS.md, flow C). */
    public const int MAX_OPEN = 3;

    public const int MAX_OPEN_PER_PROPERTY = 1;

    /** The longest message or note, in characters. */
    public const int MAX_TEXT = 500;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferred_at' => 'immutable_datetime',
            'status' => ViewingRequestStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
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
     * Open = `requested` or `accepted` with `preferred_at` still ahead. The only definition of "open",
     * for the per-renter limits and the lists.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('status'), [ViewingRequestStatus::Requested, ViewingRequestStatus::Accepted])
            ->where($query->qualifyColumn('preferred_at'), '>', self::now());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpenForRenter(Builder $query, User $renter): void
    {
        $query->where($query->qualifyColumn('renter_id'), $renter->id)->open();
    }

    /**
     * Waiting for an answer: `requested`, and the time hasn't come (the dashboard's figure and the list behind it).
     *
     * @param  Builder<self>  $query
     */
    public function scopeWaiting(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), ViewingRequestStatus::Requested)
            ->where($query->qualifyColumn('preferred_at'), '>', self::now());
    }

    /**
     * `requested` and the time has come with nobody answering: what pages show as `expired`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnanswered(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), ViewingRequestStatus::Requested)
            ->where($query->qualifyColumn('preferred_at'), '<=', self::now());
    }

    /** "Now" as a UTC binding, from Laravel's clock (so travelTo() controls it). */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(Date::now())->utc();
    }

    /**
     * What pages call the status: a `requested` one nobody answered before its time shows as `expired` (display
     * only, like a hold that ran out: the row stays `requested`, and no table move leads to "expired").
     */
    public function shownStatus(): string
    {
        return $this->status === ViewingRequestStatus::Requested && ! $this->isUpcoming() ? 'expired' : $this->status->value;
    }

    /** The preferred time is still ahead. */
    public function isUpcoming(): bool
    {
        return $this->preferred_at->isAfter(self::now());
    }

    /**
     * The transition table plus the guards that depend on the clock: accepting, declining (changing an answer
     * included) and cancelling only before `preferred_at`; marking done only from it.
     *
     * @throws InvalidViewingMove
     */
    public function assertCanMoveTo(ViewingRequestStatus $to): void
    {
        $this->status->assertCanTransitionTo($to);

        if ($to === ViewingRequestStatus::Done) {
            if ($this->isUpcoming()) {
                throw InvalidViewingMove::notYet();
            }

            return;
        }

        if (! $this->isUpcoming()) {
            throw InvalidViewingMove::timePassed();
        }
    }

    /** Whether assertCanMoveTo() lets the move through right now (for what a page offers). */
    public function canMoveTo(ViewingRequestStatus $to): bool
    {
        try {
            $this->assertCanMoveTo($to);
        } catch (BusinessRuleException) {
            return false;
        }

        return true;
    }
}
