<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ViewingRequestStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ViewingRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $property_id
 * @property int $landlord_account_id
 * @property int $renter_id
 * @property CarbonImmutable $preferred_at
 * @property ViewingRequestStatus $status
 * @property string|null $landlord_note
 * @property int|null $decided_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['property_id', 'landlord_account_id', 'renter_id', 'preferred_at', 'status', 'landlord_note', 'decided_by'])]
final class ViewingRequest extends Model
{
    /** @use HasFactory<ViewingRequestFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferred_at' => 'immutable_datetime',
            'status' => ViewingRequestStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function renter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'renter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
