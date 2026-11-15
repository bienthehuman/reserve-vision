<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The account's reservations, newest first, 15 a page, optionally one status
 * (`?status=held`; an unknown value shows all). The isolation scope limits the
 * query to the current account; the renter shows by name only: contact details
 * are on the detail page (ACCESS.md, matrix last row).
 */
final class ListReservations
{
    public const int PER_PAGE = 15;

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Reservation::class);

        $status = ReservationStatus::tryFrom($request->string('status')->toString());

        $page = Reservation::query()
            ->with(['space.room.property', 'renter'])
            ->when($status !== null, fn ($query) => $query->where('reservations.status', $status))
            ->latest('reservations.created_at')
            ->latest('reservations.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Landlord/Reservations/Index', [
            'reservations' => $page->getCollection()->map(fn (Reservation $reservation): array => [
                'id' => $reservation->id,
                'status' => $reservation->status->value,
                'renter_name' => $reservation->renter->full_name,
                'property_name' => $reservation->space->room->property->name,
                'room_name' => $reservation->space->room->name,
                'space_label' => $reservation->space->label,
                'move_in' => $reservation->move_in->toDateString(),
                'move_out' => $reservation->move_out->toDateString(),
                'reservation_fee' => $reservation->reservation_fee,
            ])->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
            ],
            'filter' => ['status' => $status?->value],
            'statuses' => array_map(fn (ReservationStatus $s): string => $s->value, ReservationStatus::cases()),
        ]);
    }
}
