<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProvinceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A province of the PSGC list, plus "Metro Manila" and the "Special Geographic Area",
 * which belong to no province (slice 4.4, docs/DATA_MODEL.md). Reference data, filled
 * by `places:import`; `slug` is set once and never changed by an import.
 *
 * @property int $id
 * @property string $psgc_code
 * @property string $name
 * @property string $slug
 */
#[Fillable(['psgc_code', 'name', 'slug'])]
final class Province extends Model
{
    /** @use HasFactory<ProvinceFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }
}
