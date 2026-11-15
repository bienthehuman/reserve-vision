<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\ArchiveSpace;
use App\Models\Space;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** POST /landlord/spaces/{space}/archive. Owners only; another account's record is a 404, and a caretaker gets 403. */
final class ArchiveSpaceController
{
    public function __invoke(Space $space, ArchiveSpace $action): RedirectResponse
    {
        Gate::authorize('archive', $space);

        $model = $action->handle($space);

        return redirect()
            ->route('landlord.properties.show', $model->room->property_id)
            ->with('status', __(':name is archived.', ['name' => $model->label]));
    }
}
