<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\MovePropertyPhoto;
use App\Http\Requests\MovePropertyPhotoRequest;
use App\Models\PropertyPhoto;
use Illuminate\Http\RedirectResponse;

/** POST /landlord/photos/{photo}/move: one place up or down. Owners only. */
final class MovePropertyPhotoController
{
    public function __invoke(MovePropertyPhotoRequest $request, PropertyPhoto $photo, MovePropertyPhoto $move): RedirectResponse
    {
        $moved = $move->handle($photo, $request->string('direction')->toString());

        return redirect()
            ->route('landlord.properties.show', $photo->property_id)
            ->with('status', $moved->sort === 1 ? __('The photo is now the cover.') : __('The photo is now number :n.', ['n' => $moved->sort]));
    }
}
