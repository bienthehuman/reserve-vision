<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AmenityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 */
#[Fillable(['name', 'slug'])]
final class Amenity extends Model
{
    /** @use HasFactory<AmenityFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsToMany<Property, $this>
     */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'property_amenity');
    }
}
