<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToLandlordAccount;
use App\Models\Scopes\LandlordAccountScope;
use Carbon\CarbonImmutable;
use Database\Factories\SpaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The reservable unit (ADR 0004).
 *
 * @property int $id
 * @property int $room_id
 * @property int $landlord_account_id
 * @property string $label
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Room $room the foreign key is required, so never null
 */
#[Fillable(['room_id', 'landlord_account_id', 'label', 'archived_at'])]
final class Space extends Model
{
    /** @use HasFactory<SpaceFactory> */
    use BelongsToLandlordAccount, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Spaces of listed rooms (Room::listed()), not archived, of every account.
     * A named escape from the isolation scope, for the public room page.
     *
     * @param  Builder<self>  $query
     */
    public function scopeListed(Builder $query): void
    {
        $query->withoutGlobalScope(LandlordAccountScope::class)
            ->whereNull($query->qualifyColumn('archived_at'))
            ->whereHas('room', fn (Builder $room) => $room->listed());
    }
}
