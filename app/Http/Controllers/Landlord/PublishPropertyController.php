<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\PublishProperty;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** POST /landlord/properties/{property}/publish. Owners only; another account's record is a 404, and a caretaker gets 403. */
final class PublishPropertyController
{
    public function __invoke(Property $property, PublishProperty $action): RedirectResponse
    {
        Gate::authorize('publish', $property);

        $model = $action->handle($property);

        return redirect()
            ->route('landlord.properties.show', $model->id)
            ->with('status', __(':name is published. Renters can now find and reserve it.', ['name' => $model->name]));
    }
}
