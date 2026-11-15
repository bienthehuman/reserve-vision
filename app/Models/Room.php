<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoomType;
use App\Models\Concerns\BelongsToLandlordAccount;
use App\Models\Scopes\LandlordAccountScope;
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
 * @property-read Property $property the foreign key is required, so never null
 */
#[Fillable([
    'property_id', 'landlord_account_id', 'name', 'room_type', 'monthly_rate',
    'deposit_months', 'advance_months', 'reservation_fee', 'archived_at',
])]
final class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use BelongsToLandlordAccount, HasFactory;

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
     * Rooms the public may see and renters may reserve, of every account: the
     * room isn't archived and its property is published (Property::published():
     * not archived, verified account). RESERVATIONS.md "Booking rules"; anything
     * else is a 404. ReserveSpace re-checks the same under its locks. A named
     * escape from the isolation scope: landlords browse listings through it too.
     *
     * @param  Builder<self>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->withoutGlobalScope(LandlordAccountScope::class)
            ->whereNull($query->qualifyColumn('archived_at'))
            ->whereHas('property', fn (Builder $property) => $property->published());
    }

    /**
     * Listed, non-archived spaces of this room with no blocking reservation
     * overlapping `[$moveIn, $moveOut)`. A plain read for the public room page,
     * through the public scopes, so a landlord looking at another account's
     * listing sees the same count as everyone; ReserveSpace decides under its locks.
     */
    public function freeSpaceCount(CarbonImmutable $moveIn, CarbonImmutable $moveOut): int
    {
        return Space::query()
            ->listed()
            ->where('spaces.room_id', $this->id)
            ->whereDoesntHave('reservations', fn (Builder $reservations) => $reservations
                ->onListedSpaces()
                ->blocking()
                ->overlapping($moveIn, $moveOut))
            ->count();
    }
}
