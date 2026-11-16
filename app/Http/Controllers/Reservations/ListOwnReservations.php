<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reservations;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My reservations", `/reservations` (slice 5.3, renter). Current first: every reservation that
 * still holds its space (Reservation::blocking(): a live hold, proof uploaded, confirmed, living
 * there), soonest move-in first; then the past ones (an expired hold, cancelled, declined, ended),
 * latest move-in first, a page at a time. Only the renter's own (`ownedByRenter`).
 */
final class ListOwnReservations
{
    public const int PAST_PER_PAGE = 10;

    public function __invoke(Request $request): Response
    {
        /** @var User $renter (auth + renter middleware) */
        $renter = $request->user();

        $mine = fn (): Builder => Reservation::query()->ownedByRenter($renter)->with('space.room.property');

        $current = $mine()->blocking()->orderBy('move_in')->orderBy('id')->get();

        $past = $mine()
            ->whereNotIn('reservations.id', $current->modelKeys())
            ->orderByDesc('move_in')->orderByDesc('id')
            ->paginate(self::PAST_PER_PAGE)
            ->withQueryString();

        return Inertia::render('Reservations/Index', [
            'current' => $current->map(self::row(...))->all(),
            'past' => $past->getCollection()->map(self::row(...))->all(),
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
    private static function row(Reservation $reservation): array
    {
        $room = $reservation->space->room;

        return [
            'id' => $reservation->id,
            'status' => $reservation->shownStatus()->value,
            'property_name' => $room->property->name,
            'place' => trim("{$room->property->barangay}, {$room->property->city}", ', '),
            'room_name' => $room->name,
            'space_label' => $reservation->space->label,
            'move_in' => $reservation->move_in->toDateString(),
            'move_out' => $reservation->move_out->toDateString(),
            'hold_expires_at' => $reservation->isLiveHold() ? $reservation->hold_expires_at?->toIso8601String() : null,
        ];
    }
}
