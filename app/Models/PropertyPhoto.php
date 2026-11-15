<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PhotoStatus;
use App\Models\Concerns\BelongsToLandlordAccount;
use Carbon\CarbonImmutable;
use Database\Factories\PropertyPhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $property_id
 * @property int $landlord_account_id
 * @property string $path
 * @property int $sort
 * @property PhotoStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['property_id', 'landlord_account_id', 'path', 'sort', 'status'])]
final class PropertyPhoto extends Model
{
    /** @use HasFactory<PropertyPhotoFactory> */
    use BelongsToLandlordAccount, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'status' => PhotoStatus::class,
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
}
