<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\UnarchiveProperty;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** DELETE /landlord/propertys/{property}/archive. Owners only; another account's record is a 404, and a caretaker gets 403. */
final class UnarchivePropertyController
{
    public function __invoke(Property $property, UnarchiveProperty $action): RedirectResponse
    {
        Gate::authorize('unarchive', $property);

        $model = $action->handle($property);

        return redirect()
            ->route('landlord.properties.show', $model->id)
            ->with('status', __(':name is back.', ['name' => $model->name]));
    }
}
