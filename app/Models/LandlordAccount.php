<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LandlordAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $contact_phone
 * @property bool $is_verified
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['name', 'slug', 'contact_phone', 'is_verified', 'verified_at'])]
final class LandlordAccount extends Model
{
    /** @use HasFactory<LandlordAccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<LandlordMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(LandlordMember::class);
    }

    /**
     * @return HasMany<LandlordInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(LandlordInvitation::class);
    }

    /**
     * @return HasMany<Property, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
