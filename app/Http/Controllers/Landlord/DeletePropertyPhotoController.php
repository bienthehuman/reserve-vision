<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\DeletePropertyPhoto;
use App\Models\PropertyPhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** DELETE /landlord/photos/{photo}: the row and its files. Owners only. */
final class DeletePropertyPhotoController
{
    public function __invoke(PropertyPhoto $photo, DeletePropertyPhoto $delete): RedirectResponse
    {
        Gate::authorize('delete', $photo);

        $delete->handle($photo);

        return redirect()
            ->route('landlord.properties.show', $photo->property_id)
            ->with('status', __('The photo is deleted.'));
    }
}
