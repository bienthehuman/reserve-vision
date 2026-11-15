<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GenderPolicy;
use App\Enums\PropertyKind;
use App\Models\Concerns\BelongsToLandlordAccount;
use App\Models\Scopes\LandlordAccountScope;
use Carbon\CarbonImmutable;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $landlord_account_id
 * @property PropertyKind $kind
 * @property string $name
 * @property string $slug
 * @property string $city
 * @property string $city_slug
 * @property string|null $barangay
 * @property string $address
 * @property string|null $lat
 * @property string|null $lng
 * @property GenderPolicy $gender_policy
 * @property string|null $house_rules
 * @property bool $is_published
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'landlord_account_id', 'kind', 'name', 'slug', 'city', 'city_slug', 'barangay', 'address',
    'lat', 'lng', 'gender_policy', 'house_rules', 'is_published', 'archived_at',
])]
final class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use BelongsToLandlordAccount, HasFactory;

    /**
     * `lat` and `lng` stay strings (DECIMAL(9,6)): no floats anywhere (DATA_MODEL.md).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PropertyKind::class,
            'gender_policy' => GenderPolicy::class,
            'is_published' => 'boolean',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<LandlordAccount, $this>
     */
    public function landlordAccount(): BelongsTo
    {
        return $this->belongsTo(LandlordAccount::class);
    }

    /**
     * @return BelongsToMany<Amenity, $this>
     */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'property_amenity');
    }

    /**
     * @return HasMany<PropertyPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(PropertyPhoto::class)->orderBy('sort');
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * @return HasMany<ViewingRequest, $this>
     */
    public function viewingRequests(): HasMany
    {
        return $this->hasMany(ViewingRequest::class);
    }

    /**
     * Properties anyone may browse, of every account: published, not archived,
     * and the landlord account is verified. A named escape from the isolation
     * scope (ACCESS.md "Isolation"): landlords browse listings through it too.
     *
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->withoutGlobalScope(LandlordAccountScope::class)
            ->where($query->qualifyColumn('is_published'), true)
            ->whereNull($query->qualifyColumn('archived_at'))
            ->whereHas('landlordAccount', fn (Builder $account) => $account->where('is_verified', true));
    }
}
