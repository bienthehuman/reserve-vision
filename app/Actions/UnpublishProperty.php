<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Property;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Takes a property off the listings (owner only: PropertyPolicy::unpublish, checked by the
 * controller). Always allowed. Nobody new can reserve it; the reservations it already has are
 * not touched and run their course. `first_published_at` stays, so the listing link is still
 * the property's when it is published again. Not published: nothing happens.
 * One audit row, `property.unpublished`.
 */
final class UnpublishProperty
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Property $property): Property
    {
        return DB::transaction(function () use ($property): Property {
            $property = Property::query()->whereKey($property->getKey())->lockForUpdate()->firstOrFail();

            if (! $property->is_published) {
                return $property;
            }

            $property->is_published = false;
            $property->save();

            $this->audit->record('property.unpublished', $property, ['is_published' => true], ['is_published' => false]);

            return $property;
        });
    }
}
