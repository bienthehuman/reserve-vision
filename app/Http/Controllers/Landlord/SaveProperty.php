<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\UpdateProperty;
use App\Http\Requests\PropertyRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;

/** PUT /landlord/properties/{property}. Owners only; another account's property is a 404. */
final class SaveProperty
{
    public function __invoke(PropertyRequest $request, Property $property, UpdateProperty $updateProperty): RedirectResponse
    {
        $property = $updateProperty->handle($property, $request->details());

        return redirect()
            ->route('landlord.properties.show', $property->id)
            ->with('status', __('Saved :name.', ['name' => $property->name]));
    }
}
