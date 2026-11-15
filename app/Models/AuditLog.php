<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToLandlordAccount;
use Carbon\CarbonImmutable;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: the database rejects UPDATE and DELETE (ADR 0008). Never call
 * save() on an existing row or delete().
 *
 * @property int $id
 * @property int|null $actor_id
 * @property int|null $landlord_account_id
 * @property string $action
 * @property string $subject_type
 * @property int $subject_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property CarbonImmutable $created_at
 */
#[Fillable(['actor_id', 'landlord_account_id', 'action', 'subject_type', 'subject_id', 'before', 'after'])]
final class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use BelongsToLandlordAccount, HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<LandlordAccount, $this>
     */
    public function landlordAccount(): BelongsTo
    {
        return $this->belongsTo(LandlordAccount::class);
    }
}
