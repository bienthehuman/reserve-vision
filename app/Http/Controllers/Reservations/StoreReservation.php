<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reservations;

use App\Actions\ReserveSpace;
use App\Http\Requests\StoreReservationRequest;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * POST /rooms/{room}/reservations. Domain errors (no space, limits, window…)
 * render through BusinessRuleException: the `error` flash back on the room
 * page, or 422 JSON when the request wants JSON.
 */
final class StoreReservation
{
    public function __invoke(StoreReservationRequest $request, int $room, ReserveSpace $reserveSpace): RedirectResponse|JsonResponse
    {
        $room = Room::query()->listed()->findOrFail($room);

        /** @var User $renter (auth + renter middleware) */
        $renter = $request->user();

        $reservation = $reserveSpace->handle($renter, $room, $request->moveIn(), $request->months());

        if ($request->expectsJson()) {
            return response()->json([
                'reservation' => [
                    'id' => $reservation->id,
                    'space_id' => $reservation->space_id,
                    'status' => $reservation->status->value,
                    'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
                ],
                'url' => route('reservations.show', $reservation->id),
            ], 201);
        }

        return redirect()->route('reservations.show', $reservation->id);
    }
}
