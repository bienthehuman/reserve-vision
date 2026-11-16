<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\DescribePropertyPhoto;
use App\Http\Requests\DescribePropertyPhotoRequest;
use App\Models\PropertyPhoto;
use Illuminate\Http\RedirectResponse;

/** PATCH /landlord/photos/{photo}: the description (`alt` text). Owners only. */
final class DescribePropertyPhotoController
{
    public function __invoke(DescribePropertyPhotoRequest $request, PropertyPhoto $photo, DescribePropertyPhoto $describe): RedirectResponse
    {
        $describe->handle($photo, $request->string('alt')->toString());

        return redirect()
            ->route('landlord.properties.show', $photo->property_id)
            ->with('status', __('The description is saved.'));
    }
}
