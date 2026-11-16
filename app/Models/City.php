<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CityKind;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A city or municipality of the PSGC list (slice 4.4). Its `slug` is in the public
 * listing URL, so it is unique nationwide and never changed by an import.
 *
 * @property int $id
 * @property int $province_id
 * @property string $psgc_code
 * @property string $name
 * @property string $slug
 * @property CityKind $kind
 * @property-read Province $province
 */
#[Fillable(['province_id', 'psgc_code', 'name', 'slug', 'kind'])]
final class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => CityKind::class];
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /**
     * @return HasMany<Barangay, $this>
     */
    public function barangays(): HasMany
    {
        return $this->hasMany(Barangay::class);
    }
}
