<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NameSuffix;
use App\Enums\UserType;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property NameSuffix|null $suffix
 * @property-read string $full_name "Joshua Gonzales Jr."
 * @property string $email
 * @property string|null $phone
 * @property UserType $type
 * @property CarbonImmutable|null $email_verified_at
 */
#[Fillable(['first_name', 'last_name', 'suffix', 'email', 'phone', 'type', 'password'])]
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
            'suffix' => NameSuffix::class,
            'type' => UserType::class,
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Names are stored trimmed with inner whitespace collapsed, so
     * "  Joshua   Gonzales " and "Joshua Gonzales" are the same name.
     *
     * @return Attribute<string, string>
     */
    protected function firstName(): Attribute
    {
        return self::tidyName();
    }

    /**
     * @return Attribute<string, string>
     */
    protected function lastName(): Attribute
    {
        return self::tidyName();
    }

    /**
     * First name, last name and the suffix when there is one: "Joshua Gonzales Jr.".
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim(
            $this->first_name.' '.$this->last_name.($this->suffix instanceof NameSuffix ? ' '.$this->suffix->label() : '')
        ));
    }

    /**
     * @return Attribute<string, string>
     */
    private static function tidyName(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): string => is_string($value) ? $value : '',
            set: fn (string $value): string => trim((string) preg_replace('/\s+/u', ' ', $value)),
        );
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
