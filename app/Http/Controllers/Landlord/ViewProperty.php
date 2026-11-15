<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Models\Property;
use App\Models\Room;
use App\Models\Space;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One property with its rooms and spaces, read-only except the rate form
 * (owners only; `can_change_rate` is the policy's answer, and the endpoint asks again).
 */
final class ViewProperty
{
    public function __invoke(Property $property): Response
    {
        Gate::authorize('view', $property);

        $rooms = $property->rooms()->with('spaces')->orderBy('rooms.name')->get();

        return Inertia::render('Landlord/Properties/Show', [
            'property' => [
                'id' => $property->id,
                'name' => $property->name,
                'kind' => $property->kind->value,
                'address' => $property->address,
                'barangay' => $property->barangay,
                'city' => $property->city,
                'is_published' => $property->is_published,
                'is_archived' => $property->archived_at !== null,
            ],
            'rooms' => $rooms->map(fn (Room $room): array => [
                'id' => $room->id,
                'name' => $room->name,
                'type' => $room->room_type->value,
                'monthly_rate' => $room->monthly_rate,
                'reservation_fee' => $room->reservation_fee,
                'deposit_months' => $room->deposit_months,
                'advance_months' => $room->advance_months,
                'is_archived' => $room->archived_at !== null,
                'can_change_rate' => Gate::allows('updateRate', $room),
                'spaces' => $room->spaces->sortBy('label')->values()->map(fn (Space $space): array => [
                    'id' => $space->id,
                    'label' => $space->label,
                    'is_archived' => $space->archived_at !== null,
                ])->all(),
            ])->all(),
        ]);
    }
}
