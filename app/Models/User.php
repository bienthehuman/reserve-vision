<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NameSuffix;
use App\Enums\UserType;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
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
 * @property string|null $middle_name
 * @property string $last_name
 * @property NameSuffix|null $suffix
 * @property-read string $full_name "Joshua Michael Gonzales Jr." (middle name and suffix when present)
 * @property string $email
 * @property string|null $phone
 * @property UserType $type
 * @property CarbonImmutable|null $email_verified_at
 */
#[Fillable(['first_name', 'middle_name', 'last_name', 'suffix', 'email', 'phone', 'type', 'password'])]
#[Hidden(['password', 'remember_token'])]
final class User extends Authenticatable implements MustVerifyEmail
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
     * Optional: blank becomes null.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function middleName(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?string => is_string($value) ? $value : null,
            set: fn (?string $value): ?string => $value === null || trim($value) === ''
                ? null
                : trim((string) preg_replace('/\s+/u', ' ', $value)),
        );
    }

    /**
     * @return Attribute<string, string>
     */
    protected function lastName(): Attribute
    {
        return self::tidyName();
    }

    /**
     * The whole name, skipping what's missing: "Joshua Michael Gonzales Jr.".
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix?->label(),
        ], fn (?string $part): bool => $part !== null && $part !== '')));
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

    public function isLandlord(): bool
    {
        return $this->type === UserType::Landlord;
    }

    /** Where the user lands after logging in when no page asked for it: / for renters, /landlord for landlords. */
    public function homePath(): string
    {
        return $this->isLandlord() ? route('landlord.dashboard', absolute: false) : route('home', absolute: false);
    }

    /** Markdown mail in the project's voice and colors (resources/views/mail/auth), queued. */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
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
