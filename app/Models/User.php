<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'type', 'password'])]
#[Hidden(['password', 'remember_token'])]
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => UserType::class,
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The landlord membership; only landlord users have one.
     *
     * @return HasOne<LandlordMember, $this>
     */
    public function landlordMember(): HasOne
    {
        return $this->hasOne(LandlordMember::class);
    }

    /**
     * Reservations this user made as a renter.
     *
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'renter_id');
    }

    /**
     * @return HasMany<ViewingRequest, $this>
     */
    public function viewingRequests(): HasMany
    {
        return $this->hasMany(ViewingRequest::class, 'renter_id');
    }
}
