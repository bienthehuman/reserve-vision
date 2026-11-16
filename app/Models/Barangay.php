<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BarangayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A barangay of the PSGC list (slice 4.4). `district` is set only in Manila, whose
 * numbered barangays are known by their district: "Barangay 395, Sampaloc" is `label`.
 *
 * @property int $id
 * @property int $city_id
 * @property string $psgc_code
 * @property string $name
 * @property string|null $district
 * @property-read string $label
 * @property-read City $city
 */
#[Fillable(['city_id', 'psgc_code', 'name', 'district'])]
final class Barangay extends Model
{
    /** @use HasFactory<BarangayFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * How an address writes the barangay: with its district where it has one.
     *
     * @return Attribute<string, never>
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => self::labelOf($this->name, $this->district));
    }

    public static function labelOf(string $name, ?string $district): string
    {
        return $district === null || $district === '' ? $name : "{$name}, {$district}";
    }
}
