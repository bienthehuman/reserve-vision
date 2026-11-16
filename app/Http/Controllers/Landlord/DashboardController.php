<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Space;
use App\Support\CurrentLandlordAccount;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The landlord dashboard (owner and caretaker): four figures, each linking to the
 * reservations list that shows exactly what was counted (`?status=` / `?show=`, the
 * same scopes). Every query goes through the isolation scope, so the figures are the
 * current account's, and the account's own `unverified` banner stays.
 *
 * - Proofs awaiting review: `proof_uploaded`, of which `overdue` waited more than 24 hours.
 * - Holds expiring: live holds that run out within 6 hours.
 * - Move-ins: `confirmed` stays starting from today to 7 days ahead (business dates).
 * - Occupancy: spaces with a stay under way today (`confirmed` or `active`, `move_in <= today < move_out`)
 *   out of all spaces that can be rented (not archived, in a room and a property that aren't either).
 */
final class DashboardController
{
    public function __invoke(CurrentLandlordAccount $current): Response
    {
        $account = $current->account();

        $total = Space::query()
            ->whereNull('spaces.archived_at')
            ->whereHas('room', fn (Builder $room) => $room
                ->whereNull('rooms.archived_at')
                ->whereHas('property', fn (Builder $property) => $property->whereNull('properties.archived_at')))
            ->count();

        $occupied = Reservation::query()
            ->underWayToday()
            ->whereHas('space', fn (Builder $space) => $space
                ->whereNull('spaces.archived_at')
                ->whereHas('room', fn (Builder $room) => $room
                    ->whereNull('rooms.archived_at')
                    ->whereHas('property', fn (Builder $property) => $property->whereNull('properties.archived_at'))))
            ->distinct()
            ->count('reservations.space_id');

        return Inertia::render('Landlord/Dashboard', [
            'account' => [
                'name' => $account->name,
                'slug' => $account->slug,
                'isVerified' => $account->is_verified,
            ],
            'role' => $current->role()->value,
            'figures' => [
                'proofs' => [
                    'count' => Reservation::query()->where('reservations.status', ReservationStatus::ProofUploaded)->count(),
                    'overdue' => Reservation::query()->proofWaitingOver(ListReservations::PROOF_REMINDER_HOURS)->count(),
                    'overdue_hours' => ListReservations::PROOF_REMINDER_HOURS,
                ],
                'holds' => [
                    'count' => Reservation::query()->holdExpiringWithin(ListReservations::HOLD_SOON_HOURS)->count(),
                    'hours' => ListReservations::HOLD_SOON_HOURS,
                ],
                'move_ins' => [
                    'count' => Reservation::query()->movingInWithin(ListReservations::MOVE_IN_SOON_DAYS)->count(),
                    'days' => ListReservations::MOVE_IN_SOON_DAYS,
                ],
                'occupancy' => ['occupied' => $occupied, 'total' => $total],
            ],
        ]);
    }
}
