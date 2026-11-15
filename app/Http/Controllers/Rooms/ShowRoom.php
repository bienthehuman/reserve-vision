<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rooms;

use App\Actions\ReserveSpace;
use App\Enums\UserType;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The minimal room page (slice 2.5): enough to reserve through the browser and
 * for the concurrency harness. Slice 5.1 replaces it with the listing page.
 */
final class ShowRoom
{
    public const int DEFAULT_MONTHS = 6;

    public function __invoke(Request $request, int $room): Response
    {
        $room = Room::query()->listed()->findOrFail($room);
        $property = $room->property()->firstOrFail();

        $today = BusinessDate::today();
        $moveIn = $this->moveIn($request) ?? $today;
        $months = $this->months($request);
        $moveOut = $moveIn->addMonthsNoOverflow($months);

        return Inertia::render('Rooms/Show', [
            'room' => [
                'id' => $room->id,
                'name' => $room->name,
                'type' => $room->room_type->value,
                'monthly_rate' => $room->monthly_rate,
                'reservation_fee' => $room->reservation_fee,
                'space_count' => $room->spaces()->whereNull('archived_at')->count(),
            ],
            'property' => [
                'name' => $property->name,
                'barangay' => $property->barangay,
                'city' => $property->city,
            ],
            'stay' => [
                'move_in' => $moveIn->toDateString(),
                'months' => $months,
                'move_out' => $moveOut->toDateString(),
            ],
            'window' => [
                'first' => $today->toDateString(),
                'last' => $today->addDays(ReserveSpace::MOVE_IN_WINDOW_DAYS)->toDateString(),
                'min_months' => ReserveSpace::MIN_MONTHS,
                'max_months' => ReserveSpace::MAX_MONTHS,
            ],
            'freeSpaces' => $room->freeSpaceCount($moveIn, $moveOut),
            'openReservation' => $this->openReservation($request->user(), $room),
        ]);
    }

    private function moveIn(Request $request): ?CarbonImmutable
    {
        $value = $request->query('move_in');

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        // Round-trip, so 2027-02-30 doesn't quietly become Mar 2.
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function months(Request $request): int
    {
        $value = filter_var($request->query('months'), FILTER_VALIDATE_INT);

        return is_int($value) && $value >= ReserveSpace::MIN_MONTHS && $value <= ReserveSpace::MAX_MONTHS
            ? $value
            : self::DEFAULT_MONTHS;
    }

    /**
     * The renter's own open reservation in this room, so the page can point to
     * it instead of offering a second one.
     *
     * @return array{id: int, space_label: string}|null
     */
    private function openReservation(mixed $user, Room $room): ?array
    {
        if (! $user instanceof User || $user->type !== UserType::Renter) {
            return null;
        }

        $reservation = Reservation::query()
            ->openForRenter($user)
            ->whereHas('space', fn ($space) => $space->where('room_id', $room->id))
            ->latest('id')
            ->first();

        return $reservation === null ? null : [
            'id' => $reservation->id,
            'space_label' => $reservation->space()->firstOrFail()->label,
        ];
    }
}
