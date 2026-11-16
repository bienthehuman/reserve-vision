<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LandlordRole;
use App\Models\Concerns\BelongsToLandlordAccount;
use App\Models\Scopes\LandlordAccountScope;
use Carbon\CarbonImmutable;
use Database\Factories\LandlordInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
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
 * @property-read LandlordAccount $landlordAccount the foreign key is required, so never null
 * @property-read User $inviter the foreign key is required, so never null
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

    /** Can still be accepted: not accepted, not revoked, not past its 7 days. */
    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * The invitation a plain token belongs to, of any account: the token is stored only as its
     * SHA-256 hash. A named escape from the isolation scope (ACCESS.md "Isolation"): the person
     * holding the link isn't a member of the account yet, and may be logged in as a landlord user
     * with no membership, for whom the scope matches nothing.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForToken(Builder $query, string $token): void
    {
        $query->withoutGlobalScope(LandlordAccountScope::class)
            ->where($query->qualifyColumn('token_hash'), hash('sha256', $token));
    }
}
