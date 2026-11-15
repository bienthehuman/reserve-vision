<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** The account's properties. The isolation scope limits the query; owners also get the "Add a property" button. */
final class ListProperties
{
    public function __invoke(): Response
    {
        Gate::authorize('viewAny', Property::class);

        $properties = Property::query()
            ->withCount(['rooms' => fn (Builder $rooms) => $rooms->whereNull('rooms.archived_at')])
            ->orderBy('properties.name')
            ->get();

        return Inertia::render('Landlord/Properties/Index', [
            'can_create' => Gate::allows('create', Property::class),
            'properties' => $properties->map(fn (Property $property): array => [
                'id' => $property->id,
                'name' => $property->name,
                'kind' => $property->kind->value,
                'barangay' => $property->barangay,
                'city' => $property->city,
                'is_published' => $property->is_published,
                'is_archived' => $property->archived_at !== null,
                'room_count' => $property->rooms_count,
            ])->all(),
        ]);
    }
}
