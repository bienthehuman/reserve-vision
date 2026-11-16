<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Enums\ViewingRequestStatus;
use App\Models\User;
use App\Models\ViewingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One viewing request of the account (slice 6.2): the property, the time (Manila), the renter's message, the
 * renter's email and phone (the matrix's "See a renter's contact details", asked of the policy as its own
 * ability), who answered and what they wrote, and the moves the table allows now. Another account's request
 * never gets here: the scope makes the route binding a 404.
 */
final class ViewViewing
{
    /** Move => [target status, ViewingRequestPolicy ability]. In the order the page shows them. */
    private const array MOVES = [
        'accept' => [ViewingRequestStatus::Accepted, 'answer'],
        'decline' => [ViewingRequestStatus::Declined, 'answer'],
        'done' => [ViewingRequestStatus::Done, 'markDone'],
    ];

    public function __invoke(Request $request, ViewingRequest $viewing): Response
    {
        Gate::authorize('view', $viewing);

        /** @var User $user (auth + landlord middleware) */
        $user = $request->user();
        $viewing->load(['property', 'renter', 'decider']);
        $contact = Gate::forUser($user)->allows('viewRenterContact', $viewing);

        $moves = [];

        foreach (self::MOVES as $key => [$to, $ability]) {
            if ($viewing->status->canTransitionTo($to) && Gate::forUser($user)->allows($ability, $viewing)) {
                $moves[] = ['key' => $key, 'allowed' => $viewing->canMoveTo($to)];
            }
        }

        return Inertia::render('Landlord/Viewings/Show', [
            'viewing' => [
                'id' => $viewing->id,
                'status' => $viewing->status->value,
                'preferred_at' => $viewing->preferred_at->toIso8601String(),
                'is_upcoming' => $viewing->isUpcoming(),
                'message' => $viewing->message,
                'landlord_note' => $viewing->landlord_note,
                'decided_by' => $viewing->decider?->full_name,
                'created_at' => $viewing->created_at->toIso8601String(),
                'max_note' => ViewingRequest::MAX_TEXT,
            ],
            'moves' => $moves,
            'property' => ['id' => $viewing->property->id, 'name' => $viewing->property->name, 'address' => $viewing->property->full_address],
            'renter' => [
                'name' => $viewing->renter->full_name,
                'email' => $contact ? $viewing->renter->email : null,
                'phone' => $contact ? $viewing->renter->phone : null,
            ],
        ]);
    }
}
