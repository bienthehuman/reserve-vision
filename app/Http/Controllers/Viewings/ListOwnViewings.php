<?php

declare(strict_types=1);

namespace App\Http\Controllers\Viewings;

use App\Enums\ViewingRequestStatus;
use App\Models\LandlordAccount;
use App\Models\Property;
use App\Models\User;
use App\Models\ViewingRequest;
use App\Support\Listings\ListingCards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My viewings", `/viewings` (slice 6.2, renter). Coming up first: the open requests (ViewingRequest::open(): requested or
 * accepted, the time still ahead), soonest first; then the past ones (declined, cancelled, done, or whose time has gone),
 * latest first, a page at a time. Only the renter's own. Once a request is accepted it carries the landlord account's
 * contact phone and the property's full address (ACCESS.md "Contact details for viewings"); before that, neither.
 */
final class ListOwnViewings
{
    public const int PAST_PER_PAGE = 10;

    public function __invoke(Request $request): Response
    {
        /** @var User $renter (auth + renter middleware) */
        $renter = $request->user();

        $mine = fn (): Builder => ViewingRequest::query()->where('viewing_requests.renter_id', $renter->id)->with('property');

        $current = $mine()->open()->orderBy('preferred_at')->orderBy('id')->get();

        $past = $mine()
            ->whereNotIn('viewing_requests.id', $current->modelKeys())
            ->orderByDesc('preferred_at')->orderByDesc('id')
            ->paginate(self::PAST_PER_PAGE)
            ->withQueryString();

        $shown = $current->concat($past->getCollection());
        $accounts = LandlordAccount::query()->whereIn('id', $shown->pluck('landlord_account_id')->unique()->all())->get()->keyBy('id');
        $listed = Property::query()->published()->whereIn('properties.id', $shown->pluck('property_id')->unique()->all())->pluck('properties.id')->all();

        $row = fn (ViewingRequest $viewing): array => self::row($viewing, $accounts->get($viewing->landlord_account_id), in_array($viewing->property_id, $listed, true));

        return Inertia::render('Viewings/Index', [
            'current' => $current->map($row)->all(),
            'past' => $past->getCollection()->map($row)->all(),
            'pagination' => [
                'page' => $past->currentPage(),
                'last_page' => $past->lastPage(),
                'total' => $past->total(),
                'prev_url' => $past->previousPageUrl(),
                'next_url' => $past->nextPageUrl(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private static function row(ViewingRequest $viewing, ?LandlordAccount $account, bool $listed): array
    {
        $property = $viewing->property;
        $accepted = $viewing->status === ViewingRequestStatus::Accepted;

        return [
            'id' => $viewing->id,
            'status' => $viewing->shownStatus(),
            'property_name' => $property->name,
            'place' => trim("{$property->barangay}, {$property->city}", ', '),
            'listing_url' => $listed ? ListingCards::url($property) : null,
            'preferred_at' => $viewing->preferred_at->toIso8601String(),
            'is_upcoming' => $viewing->isUpcoming(),
            'message' => $viewing->message,
            'landlord_note' => $viewing->landlord_note,
            'can_cancel' => $viewing->canMoveTo(ViewingRequestStatus::Cancelled),
            // Only once the landlord has accepted.
            'meeting' => $accepted ? [
                'address' => $property->full_address,
                'landlord' => $account?->name,
                'phone' => $account?->contact_phone,
            ] : null,
        ];
    }
}
