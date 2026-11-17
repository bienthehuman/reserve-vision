<?php

declare(strict_types=1);

namespace App\Http\Controllers\Listings;

use App\Actions\ReserveSpace;
use App\Enums\UserType;
use App\Models\Amenity;
use App\Models\LandlordAccount;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Space;
use App\Models\User;
use App\Models\ViewingRequest;
use App\Support\BusinessDate;
use App\Support\Listings\ListingCards;
use App\Support\Listings\ListingSeo;
use App\Support\Listings\Stay;
use App\Support\Viewings\ViewingSlots;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /listings/{city}/{slug} (slice 5.1): the public page of a published property. Everything
 * goes through the named public scopes, so another account's listing looks the same to a
 * landlord as to anyone, and an unpublished, archived or unverified one is a 404.
 * No phone, email or payment instructions, and nothing about other renters.
 */
final class ShowListing
{
    public function __invoke(Request $request, string $city, string $slug): Response
    {
        $property = Property::query()->published()
            ->where('properties.city_slug', $city)
            ->where('properties.slug', $slug)
            ->firstOrFail();

        $stay = Stay::fromRequest($request);
        $today = BusinessDate::today();

        $rooms = Room::query()->listed()->where('rooms.property_id', $property->id)
            ->orderBy('rooms.monthly_rate')->orderBy('rooms.id')->get();

        /** @var list<int> $roomIds */
        $roomIds = array_values(array_map(intval(...), $rooms->modelKeys()));
        $count = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;

        $bedCounts = Space::query()->listed()->whereIn('spaces.room_id', $roomIds)
            ->groupBy('spaces.room_id')->selectRaw('spaces.room_id as room_id, count(*) as total')
            ->pluck('total', 'room_id');

        $open = $this->openReservations($request->user(), $roomIds);

        $roomProps = $rooms->map(fn (Room $room): array => [
            'id' => $room->id,
            'name' => $room->name,
            'type' => $room->room_type->value,
            'monthly_rate' => $room->monthly_rate,
            'reservation_fee' => $room->reservation_fee,
            'deposit_months' => $room->deposit_months,
            'advance_months' => $room->advance_months,
            'space_count' => $count($bedCounts->get($room->id)),
            'free_spaces' => $room->freeSpaceCount($stay->moveIn, $stay->moveOut()),
            'open_reservation' => $open[$room->id] ?? null,
        ])->values()->all();

        /** @var list<string> $amenities */
        $amenities = Amenity::query()->whereIn('id', $property->amenities()->select('amenities.id'))->orderBy('name')->pluck('name')->all();

        /** @var list<PropertyPhoto> $photos */
        $photos = PropertyPhoto::query()->shown()->where('property_photos.property_id', $property->id)->get()->all();

        $seo = ListingSeo::for(
            $property,
            array_values(array_map(fn (array $room): array => ['id' => $room['id'], 'name' => $room['name'], 'monthly_rate' => $room['monthly_rate']], $roomProps)),
            $amenities,
            $photos,
        );

        return Inertia::render('Listings/Show', [
            'listing' => [
                'id' => $property->id,
                'name' => $property->name,
                'kind' => $property->kind->value,
                'gender_policy' => $property->gender_policy->value,
                'address' => $property->full_address,
                'barangay' => $property->barangay,
                'city' => $property->city,
                'maps_url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($property->full_address.', Philippines'),
                'house_rules' => $property->house_rules,
                'landlord' => LandlordAccount::query()->whereKey($property->landlord_account_id)->value('name'),
                'from_rate' => $roomProps === [] ? null : min(array_column($roomProps, 'monthly_rate')),
                'amenities' => $amenities,
                'photos' => array_map(fn (PropertyPhoto $photo): array => [
                    'id' => $photo->id,
                    'alt' => $photo->altText($property->name),
                    'thumb' => $photo->thumb_url,
                    'full' => $photo->full_url,
                    'width' => $photo->width,
                    'height' => $photo->height,
                    'thumb_width' => ListingCards::THUMB_WIDTH,
                ], $photos),
            ],
            'rooms' => $roomProps,
            'stay' => [
                'move_in' => $stay->moveIn->toDateString(),
                'months' => $stay->months,
                'move_out' => $stay->moveOut()->toDateString(),
            ],
            'window' => [
                'first' => $today->toDateString(),
                'last' => $today->addDays(ReserveSpace::MOVE_IN_WINDOW_DAYS)->toDateString(),
                'min_months' => ReserveSpace::MIN_MONTHS,
                'max_months' => ReserveSpace::MAX_MONTHS,
            ],
            'viewing' => $this->viewing($request->user(), $property),
            'listingUrl' => ListingCards::url($property),
            // The reserve forms are plain HTML posts (they work without JavaScript), so they carry the token themselves.
            'csrf' => csrf_token(),
            'seo' => $seo,
        ]);
    }

    /**
     * The renter's own open reservation in each room, so the page points to it instead of offering a second one.
     *
     * @param  list<int>  $roomIds
     * @return array<int, array{id: int, space_label: string}>
     */
    private function openReservations(mixed $user, array $roomIds): array
    {
        if (! $user instanceof User || $user->type !== UserType::Renter || $roomIds === []) {
            return [];
        }

        $found = [];
        Reservation::query()->openForRenter($user)->with('space')->latest('id')->get()
            ->each(function (Reservation $reservation) use (&$found, $roomIds): void {
                $space = $reservation->space;
                if (in_array($space->room_id, $roomIds, true) && ! isset($found[$space->room_id])) {
                    $found[$space->room_id] = ['id' => $reservation->id, 'space_label' => $space->label];
                }
            });

        return $found;
    }

    /**
     * The "ask for a viewing" form (slice 6.2): the earliest open slot (the form's default), the days and times to
     * choose from, and the renter's own open request here, so the page points to it instead of offering a second.
     *
     * @return array{earliest: array{date: string, time: string}, dates: list<string>, times: list<string>, max_message: int, open: array{id: int, status: string, preferred_at: string}|null}
     */
    private function viewing(mixed $user, Property $property): array
    {
        $earliest = ViewingSlots::earliest();
        $last = ViewingSlots::lastDay();
        $dates = [];

        for ($day = $earliest->startOfDay(); $day->lte($last); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        $open = $user instanceof User && $user->type === UserType::Renter
            ? ViewingRequest::query()->openForRenter($user)->where('viewing_requests.property_id', $property->id)->orderBy('preferred_at')->first()
            : null;

        return [
            'earliest' => ['date' => $earliest->toDateString(), 'time' => $earliest->format('H:i')],
            'dates' => $dates,
            'times' => ViewingSlots::times(),
            'max_message' => ViewingRequest::MAX_TEXT,
            'open' => $open === null ? null : ['id' => $open->id, 'status' => $open->status->value, 'preferred_at' => $open->preferred_at->toIso8601String()],
        ];
    }
}
