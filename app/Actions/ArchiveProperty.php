<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\Domain\CannotArchive;
use App\Models\Property;
use App\Models\Room;
use App\Support\AuditLogger;
use App\Support\BlockingReservations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Archives a property (owner only: PropertyPolicy::archive, checked by the controller) and
 * unpublishes it. Refused while a reservation that holds any space of any of its rooms exists
 * (`Reservation::blocking()`, any dates), naming them. Already archived: nothing happens.
 *
 * Lock order: every room row of the property, by id, THEN the property row: the same
 * order as ReserveSpace (room, then a locking read of the property), so the two can't
 * wait on each other. One audit row, `property.archived`, that records `is_published` too.
 */
final class ArchiveProperty
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotArchive */
    public function handle(Property $property): Property
    {
        return DB::transaction(function () use ($property): Property {
            $roomIds = array_values(Room::query()->where('property_id', $property->getKey())->orderBy('id')->lockForUpdate()->pluck('id')
                ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all());
            $property = Property::query()->whereKey($property->getKey())->lockForUpdate()->firstOrFail();

            if ($property->archived_at !== null) {
                return $property;
            }

            $blocking = BlockingReservations::on(BlockingReservations::spaceIdsOf($roomIds));

            if ($blocking->isNotEmpty()) {
                throw CannotArchive::blockedBy($property->name, $blocking);
            }

            $before = ['archived_at' => null, 'is_published' => $property->is_published];
            $property->archived_at = CarbonImmutable::instance(Date::now());
            $property->is_published = false;
            $property->save();

            $this->audit->record('property.archived', $property, $before, ['archived_at' => $property->archived_at, 'is_published' => false]);

            return $property;
        });
    }
}
