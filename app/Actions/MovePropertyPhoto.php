<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Moves a photo one place up (towards the cover) or down (owner only: PropertyPhotoPolicy::reorder,
 * checked by the request). The first photo is the cover. Locks the property row first, like
 * every action that changes the order, then renumbers its photos 1, 2, 3…
 * One audit row, `photo.moved`, on the photo that was moved; none at either end of the list.
 */
final class MovePropertyPhoto
{
    public const string UP = 'up';

    public const string DOWN = 'down';

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PropertyPhoto $photo, string $direction): PropertyPhoto
    {
        return DB::transaction(function () use ($photo, $direction): PropertyPhoto {
            Property::query()->whereKey($photo->property_id)->lockForUpdate()->firstOrFail();
            /** @var list<PropertyPhoto> $photos */
            $photos = PropertyPhoto::query()->where('property_id', $photo->property_id)->orderBy('sort')->orderBy('id')->lockForUpdate()->get()->all();

            $from = array_find_key($photos, fn (PropertyPhoto $other): bool => $other->is($photo));

            if ($from === null) {
                return $photo; // deleted a moment ago
            }

            $moved = $photos[$from];
            $before = ['sort' => $moved->sort];
            $to = $direction === self::UP ? $from - 1 : $from + 1;

            if (isset($photos[$to])) {
                [$photos[$from], $photos[$to]] = [$photos[$to], $photos[$from]];
            }

            foreach ($photos as $index => $each) {
                $each->sort = $index + 1;
                $each->save();
            }

            $this->audit->record('photo.moved', $moved, $before, ['sort' => $moved->sort]);

            return $moved;
        });
    }
}
