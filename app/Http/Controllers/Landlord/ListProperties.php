<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** The account's properties (read-only until 4.1). The isolation scope limits the query. */
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
