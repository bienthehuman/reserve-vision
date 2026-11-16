<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\RenameSpace;
use App\Http\Requests\RenameSpaceRequest;
use App\Models\Space;
use Illuminate\Http\RedirectResponse;

/** PATCH /landlord/spaces/{space}: the new label. Owners only. */
final class RenameSpaceController
{
    public function __invoke(RenameSpaceRequest $request, Space $space, RenameSpace $renameSpace): RedirectResponse
    {
        $space = $renameSpace->handle($space, $request->string('label')->toString());

        return redirect()
            ->route('landlord.properties.show', $space->room->property_id)
            ->with('status', __('Renamed to :label.', ['label' => $space->label]));
    }
}
