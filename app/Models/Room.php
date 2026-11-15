<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoomType;
use Carbon\CarbonImmutable;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money columns are integer centavos.
 *
 * @property int $id
 * @property int $property_id
 * @property int $landlord_account_id
 * @property string $name
 * @property RoomType $room_type
 * @property int $monthly_rate
 * @property int $deposit_months
 * @property int $advance_months
 * @property int $reservation_fee
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'property_id', 'landlord_account_id', 'name', 'room_type', 'monthly_rate',
    'deposit_months', 'advance_months', 'reservation_fee', 'archived_at',
])]
final class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'room_type' => RoomType::class,
            'monthly_rate' => 'integer',
            'deposit_months' => 'integer',
            'advance_months' => 'integer',
            'reservation_fee' => 'integer',
            'archived_at' => 'immutable_datetime',
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
     * @return HasMany<Space, $this>
     */
    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }

    /**
     * Rooms the public may see and renters may reserve: the room isn't archived,
     * its property is published and not archived, and the landlord account is
     * verified (RESERVATIONS.md "Booking rules"). Anything else is a 404.
     * ReserveSpace re-checks the same under its locks.
     *
     * @param  Builder<self>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('archived_at'))
            ->whereHas('property', fn (Builder $property) => $property
                ->where('is_published', true)
                ->whereNull('archived_at')
                ->whereHas('landlordAccount', fn (Builder $account) => $account->where('is_verified', true)));
    }

    /**
     * Non-archived spaces with no blocking reservation overlapping
     * `[$moveIn, $moveOut)`. A plain read, for display only: ReserveSpace
     * decides under its locks.
     */
    public function freeSpaceCount(CarbonImmutable $moveIn, CarbonImmutable $moveOut): int
    {
        return $this->spaces()
            ->whereNull('archived_at')
            ->whereDoesntHave('reservations', fn (Builder $reservations) => $reservations
                ->blocking()
                ->overlapping($moveIn, $moveOut))
            ->count();
    }
}
