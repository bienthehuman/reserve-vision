<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\RetryPropertyPhoto;
use App\Http\Requests\PropertyPhotoUploadRequest;
use App\Models\PropertyPhoto;
use Illuminate\Http\RedirectResponse;

/** POST /landlord/photos/{photo}/retry: the file again, for a photo that failed. Owners only. */
final class RetryPropertyPhotoController
{
    public function __invoke(PropertyPhotoUploadRequest $request, PropertyPhoto $photo, RetryPropertyPhoto $retry): RedirectResponse
    {
        $retry->handle($photo, $request->photo());

        return redirect()
            ->route('landlord.properties.show', $photo->property_id)
            ->with('status', __('The photo is uploaded again. It shows here once it is resized.'));
    }
}
