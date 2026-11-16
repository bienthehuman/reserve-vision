<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\ArchiveProperty;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** POST /landlord/propertys/{property}/archive. Owners only; another account's record is a 404, and a caretaker gets 403. */
final class ArchivePropertyController
{
    public function __invoke(Property $property, ArchiveProperty $action): RedirectResponse
    {
        Gate::authorize('archive', $property);

        $model = $action->handle($property);

        return redirect()
            ->route('landlord.properties.show', $model->id)
            ->with('status', __(':name is archived.', ['name' => $model->name]));
    }
}
