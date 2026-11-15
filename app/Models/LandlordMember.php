<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LandlordRole;
use Carbon\CarbonImmutable;
use Database\Factories\LandlordMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $landlord_account_id
 * @property int $user_id
 * @property LandlordRole $role
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user the foreign key is required, so never null
 */
#[Fillable(['landlord_account_id', 'user_id', 'role'])]
final class LandlordMember extends Model
{
    /** @use HasFactory<LandlordMemberFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => LandlordRole::class,
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
