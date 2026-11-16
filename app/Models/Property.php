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
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property string|null $address the street address: the one optional part
 * @property string $barangay
 * @property string $city
 * @property string $city_slug
 * @property string $province
 * @property-read string $full_address
 * @property GenderPolicy $gender_policy
 * @property string|null $house_rules
 * @property string|null $payment_instructions
 * @property bool $is_published
 * @property CarbonImmutable|null $first_published_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'landlord_account_id', 'kind', 'name', 'slug', 'address', 'barangay', 'city', 'city_slug', 'province',
    'gender_policy', 'house_rules', 'payment_instructions', 'is_published', 'first_published_at', 'archived_at',
])]
final class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use BelongsToLandlordAccount, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PropertyKind::class,
            'gender_policy' => GenderPolicy::class,
            'is_published' => 'boolean',
            'first_published_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The address as it is written in the Philippines: street address (when given), barangay, city, province.
     *
     * @return Attribute<string, never>
     */
    protected function fullAddress(): Attribute
    {
        return Attribute::get(fn (): string => implode(', ', array_filter(
            [$this->address, $this->barangay, $this->city, $this->province],
            fn (?string $part): bool => $part !== null && $part !== '',
        )));
    }

    /** The public URL has existed: the slug and the city can no longer change (UpdateProperty). */
    public function hasBeenPublished(): bool
    {
        return $this->first_published_at !== null;
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
