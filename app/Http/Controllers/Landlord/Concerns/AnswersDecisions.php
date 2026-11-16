<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord\Concerns;

use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The answer to a decision on a reservation (slice 6.1): back to the reservation page with the
 * confirmation, or `200 {message, status}` for JSON (the concurrency suite). A refusal is the
 * domain exception's: the `error` flash, or `422 {message}`.
 */
trait AnswersDecisions
{
    private function answer(Request $request, Reservation $reservation, string $message): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message, 'status' => $reservation->status->value])
            : redirect()->route('landlord.reservations.show', $reservation->id)->with('status', $message);
    }
}
