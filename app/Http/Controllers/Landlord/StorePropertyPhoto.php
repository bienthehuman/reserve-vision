<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\UploadPropertyPhoto;
use App\Http\Requests\PropertyPhotoUploadRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;

/** POST /landlord/properties/{property}/photos: one photo file; the resize is queued. Owners only. */
final class StorePropertyPhoto
{
    public function __invoke(PropertyPhotoUploadRequest $request, Property $property, UploadPropertyPhoto $upload): RedirectResponse
    {
        $upload->handle($property, $request->photo());

        return redirect()
            ->route('landlord.properties.show', $property)
            ->with('status', __('The photo is uploaded. It shows here once it is resized.'));
    }
}
