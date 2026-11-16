<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\UnarchiveSpace;
use App\Models\Space;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** DELETE /landlord/spaces/{space}/archive. Owners only; another account's record is a 404, and a caretaker gets 403. */
final class UnarchiveSpaceController
{
    public function __invoke(Space $space, UnarchiveSpace $action): RedirectResponse
    {
        Gate::authorize('unarchive', $space);

        $model = $action->handle($space);

        return redirect()
            ->route('landlord.properties.show', $model->room->property_id)
            ->with('status', __(':name is back.', ['name' => $model->label]));
    }
}
