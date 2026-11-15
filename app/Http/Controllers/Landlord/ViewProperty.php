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
 * One property with its rooms and spaces. Read-only for a caretaker; for an owner the
 * `can_*` flags (the policies' answers; every endpoint asks again) switch on the edit,
 * add and archive buttons.
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
                'gender_policy' => $property->gender_policy->value,
                'house_rules' => $property->house_rules,
                'payment_instructions' => $property->payment_instructions,
                'is_published' => $property->is_published,
                'is_archived' => $property->archived_at !== null,
                'can_edit' => Gate::allows('update', $property),
                'can_add_room' => Gate::allows('create', [Room::class, $property]),
                'can_archive' => Gate::allows('archive', $property),
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
                'can_edit' => Gate::allows('update', $room),
                'can_archive' => Gate::allows('archive', $room),
                'can_add_space' => $room->room_type->value === 'shared' && Gate::allows('create', [Space::class, $room]),
                'spaces' => $room->spaces->sortBy('label', SORT_NATURAL)->values()->map(fn (Space $space): array => [
                    'id' => $space->id,
                    'label' => $space->label,
                    'is_archived' => $space->archived_at !== null,
                    'can_edit' => Gate::allows('update', $space),
                    'can_archive' => Gate::allows('archive', $space) && $room->room_type->value === 'shared',
                ])->all(),
            ])->all(),
        ]);
    }
}
