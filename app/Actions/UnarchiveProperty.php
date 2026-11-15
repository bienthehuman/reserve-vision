<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Property;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Brings an archived property back, as a draft: it stays unpublished until the owner
 * publishes it (4.3). Owner only: PropertyPolicy::unarchive, checked by the controller.
 * Not archived: nothing happens. One audit row, `property.unarchived`.
 */
final class UnarchiveProperty
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Property $property): Property
    {
        return DB::transaction(function () use ($property): Property {
            $property = Property::query()->whereKey($property->getKey())->lockForUpdate()->firstOrFail();

            if ($property->archived_at === null) {
                return $property;
            }

            $before = ['archived_at' => $property->archived_at];
            $property->archived_at = null;
            $property->save();

            $this->audit->record('property.unarchived', $property, $before, ['archived_at' => null]);

            return $property;
        });
    }
}
