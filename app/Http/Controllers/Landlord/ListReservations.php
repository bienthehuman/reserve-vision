<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The account's reservations, newest first, 15 a page, optionally one status
 * (`?status=held`; an unknown value shows all) and optionally one of the dashboard's lists
 * (`?show=holds_expiring`, see SHOWS). The isolation scope limits the
 * query to the current account; the renter shows by name only: contact details
 * are on the detail page (ACCESS.md, matrix last row).
 */
final class ListReservations
{
    public const int PER_PAGE = 15;

    /** The dashboard's thresholds (RESERVATIONS.md "Unanswered proofs"; the other two are slice 4.1's). */
    public const int PROOF_REMINDER_HOURS = 24;

    public const int HOLD_SOON_HOURS = 6;

    public const int MOVE_IN_SOON_DAYS = 7;

    /** `?show=` values, the lists behind the dashboard's cards. They combine with `?status=`. */
    public const array SHOWS = ['proofs_overdue', 'holds_expiring', 'moving_in'];

    /**
     * @param  Builder<Reservation>  $query
     */
    public static function applyShow(Builder $query, ?string $show): void
    {
        match ($show) {
            'proofs_overdue' => $query->proofWaitingOver(self::PROOF_REMINDER_HOURS),
            'holds_expiring' => $query->holdExpiringWithin(self::HOLD_SOON_HOURS),
            'moving_in' => $query->movingInWithin(self::MOVE_IN_SOON_DAYS),
            default => null,
        };
    }

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Reservation::class);

        $status = ReservationStatus::tryFrom($request->string('status')->toString());
        $show = in_array($request->string('show')->toString(), self::SHOWS, true) ? $request->string('show')->toString() : null;

        $page = Reservation::query()
            ->with(['space.room.property', 'renter'])
            ->when($status !== null, fn ($query) => $query->where('reservations.status', $status))
            ->tap(fn (Builder $query) => self::applyShow($query, $show))
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
            'filter' => ['status' => $status?->value, 'show' => $show],
            'statuses' => array_map(fn (ReservationStatus $s): string => $s->value, ReservationStatus::cases()),
        ]);
    }
}
