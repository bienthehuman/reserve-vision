<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Models\Property;
use App\Models\Room;
use App\Models\Space;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Search (slice 5.2): every filter is a SQL condition, nothing is filtered in PHP. The rules about
 * dates come from `Space::freeDuring()` and so from `Reservation::blocking()` / `overlapping()`, the
 * definitions `ReserveSpace` uses; there is no second copy of them here. The rest is the named public
 * scopes `Property::published()` and `Room::listed()`.
 *
 * A property matches when it passes the property filters and has at least one room that passes the
 * room filters; a room passes when its rate is in range and, with a date, it has a free space.
 */
final class ListingSearch
{
    /**
     * Properties that match, in the order asked for.
     *
     * @return Builder<Property>
     */
    public static function properties(SearchFilters $filters): Builder
    {
        $query = Property::query()->published()
            ->where(fn (Builder $property) => self::propertyFilters($property, $filters))
            ->whereHas('rooms', fn (Builder $rooms) => self::roomFilters($rooms->listed(), $filters));

        if ($filters->sort === SearchFilters::SORT_NEWEST) {
            return $query->orderByDesc('properties.first_published_at')->orderByDesc('properties.id');
        }

        // Cheapest matching room first; the same room conditions as above.
        $cheapest = Room::query()->listed()
            ->whereColumn('rooms.property_id', 'properties.id')
            ->tap(fn (Builder $rooms) => self::roomFilters($rooms, $filters))
            ->selectRaw('min(rooms.monthly_rate)');

        return $query->orderBy($cheapest)->orderByDesc('properties.first_published_at')->orderByDesc('properties.id');
    }

    /**
     * The rooms that match, of properties that match (T10 compares this with a brute-force check).
     *
     * @return Builder<Room>
     */
    public static function rooms(SearchFilters $filters): Builder
    {
        return Room::query()->listed()
            ->whereHas('property', fn (Builder $property) => $property->published()
                ->where(fn (Builder $inner) => self::propertyFilters($inner, $filters)))
            ->tap(fn (Builder $rooms) => self::roomFilters($rooms, $filters));
    }

    /**
     * What each property on a page shows: how many rooms match, the cheapest matching rate and, with
     * a date, how many beds are free. One query for the whole page.
     *
     * @param  list<int>  $propertyIds
     * @return array<int, array{rooms: int, from_rate: int, beds_free: int|null}>
     */
    public static function summaries(array $propertyIds, SearchFilters $filters): array
    {
        if ($propertyIds === []) {
            return [];
        }

        $rooms = Room::query()->listed()->whereIn('rooms.property_id', $propertyIds)
            ->tap(fn (Builder $query) => self::roomFilters($query, $filters));

        if ($filters->moveIn !== null && $filters->moveOut() !== null) {
            $in = $filters->moveIn;
            $out = $filters->moveOut();
            $rooms->withCount(['spaces as free_spaces' => fn (Builder $spaces) => self::free($spaces, $in, $out)]);
        }

        $summaries = [];

        foreach ($rooms->get(['rooms.id', 'rooms.property_id', 'rooms.monthly_rate']) as $room) {
            $summary = $summaries[$room->property_id] ?? ['rooms' => 0, 'from_rate' => $room->monthly_rate, 'beds_free' => $filters->moveIn === null ? null : 0];
            $summary['rooms']++;
            $summary['from_rate'] = min($summary['from_rate'], $room->monthly_rate);
            if ($summary['beds_free'] !== null) {
                $free = $room->getAttribute('free_spaces');
                $summary['beds_free'] += is_numeric($free) ? (int) $free : 0;
            }
            $summaries[$room->property_id] = $summary;
        }

        return $summaries;
    }

    /**
     * @param  Builder<Space>  $spaces
     */
    private static function free(Builder $spaces, CarbonImmutable $in, CarbonImmutable $out): void
    {
        $spaces->freeDuring($in, $out);
    }

    /**
     * @param  Builder<Property>  $property
     */
    private static function propertyFilters(Builder $property, SearchFilters $filters): void
    {
        if ($filters->barangay !== null) {
            $property->where('properties.barangay_id', $filters->barangay->id);
        }

        if ($filters->city !== null) {
            $property->where('properties.city_slug', $filters->city->slug);
        }

        if ($filters->kind !== null) {
            $property->where('properties.kind', $filters->kind);
        }

        if (($policies = $filters->policies()) !== null) {
            $property->whereIn('properties.gender_policy', $policies);
        }

        foreach ($filters->amenities as $slug) {
            $property->whereHas('amenities', fn (Builder $amenity) => $amenity->where('amenities.slug', $slug));
        }
    }

    /**
     * @param  Builder<Room>  $rooms
     */
    private static function roomFilters(Builder $rooms, SearchFilters $filters): void
    {
        if ($filters->minRate !== null) {
            $rooms->where('rooms.monthly_rate', '>=', $filters->minRate);
        }

        if ($filters->maxRate !== null) {
            $rooms->where('rooms.monthly_rate', '<=', $filters->maxRate);
        }

        if ($filters->moveIn !== null && ($out = $filters->moveOut()) !== null) {
            $in = $filters->moveIn;
            $rooms->whereHas('spaces', fn (Builder $spaces) => self::free($spaces, $in, $out));
        }
    }
}
