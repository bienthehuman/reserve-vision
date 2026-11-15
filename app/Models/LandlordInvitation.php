<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LandlordRole;
use App\Models\Concerns\BelongsToLandlordAccount;
use Carbon\CarbonImmutable;
use Database\Factories\LandlordInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $landlord_account_id
 * @property string $email
 * @property LandlordRole $role
 * @property string $token_hash
 * @property int $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['landlord_account_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
final class LandlordInvitation extends Model
{
    /** @use HasFactory<LandlordInvitationFactory> */
    use BelongsToLandlordAccount, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => LandlordRole::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
