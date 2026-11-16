<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\Domain\CannotPublish;
use App\Models\LandlordAccount;
use App\Models\Property;
use App\Models\Room;
use App\Support\AuditLogger;
use App\Support\PublishChecklist;
use Illuminate\Support\Facades\DB;

/**
 * Publishes a property (owner only: PropertyPolicy::publish, checked by the controller): renters
 * can find and reserve it. Refused, naming what is missing, unless the whole PublishChecklist is
 * done; the page shows the same list, and this checks it again on rows it has locked.
 * Sets `first_published_at` the first time, which locks the slug and city (UpdateProperty).
 * Already published: nothing happens.
 *
 * Lock order: the account row (shared: `landlord:verify --revoke` takes it before the properties),
 * every room row of the property by id, THEN the property row: ArchiveProperty's order, so an
 * archive of the property, of its last room or of its last space can't interleave with the check.
 * Photos change only under the property row's lock (slice 4.2). One audit row, `property.published`.
 */
final class PublishProperty
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotPublish */
    public function handle(Property $property): Property
    {
        return DB::transaction(function () use ($property): Property {
            LandlordAccount::query()->whereKey($property->landlord_account_id)->sharedLock()->firstOrFail();
            Room::query()->where('property_id', $property->getKey())->orderBy('id')->lockForUpdate()->pluck('id');
            $property = Property::query()->whereKey($property->getKey())->lockForUpdate()->firstOrFail();

            if ($property->is_published) {
                return $property;
            }

            $checklist = PublishChecklist::of($property, locking: true);

            if (! $checklist->isComplete()) {
                throw CannotPublish::missing($property->name, $checklist->missing());
            }

            $before = ['is_published' => false, 'first_published_at' => $property->first_published_at];
            $property->is_published = true;
            $property->first_published_at ??= now()->toImmutable();
            $property->save();

            $this->audit->record('property.published', $property, $before, [
                'is_published' => true,
                'first_published_at' => $property->first_published_at,
            ]);

            return $property;
        }, attempts: 3);
    }
}
