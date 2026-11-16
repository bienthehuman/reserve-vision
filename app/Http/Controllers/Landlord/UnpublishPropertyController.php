<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\UnpublishProperty;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** DELETE /landlord/properties/{property}/publish. Owners only; another account's record is a 404, and a caretaker gets 403. */
final class UnpublishPropertyController
{
    public function __invoke(Property $property, UnpublishProperty $action): RedirectResponse
    {
        Gate::authorize('unpublish', $property);

        $model = $action->handle($property);

        return redirect()
            ->route('landlord.properties.show', $model->id)
            ->with('status', __(':name is no longer published. Reservations it already has are not affected.', ['name' => $model->name]));
    }
}
