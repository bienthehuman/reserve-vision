<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\CreateProperty;
use App\Http\Requests\PropertyRequest;
use App\Support\CurrentLandlordAccount;
use Illuminate\Http\RedirectResponse;

/** POST /landlord/properties: a new draft property. Owners only (the request asks the policy first). */
final class StoreProperty
{
    public function __invoke(PropertyRequest $request, CurrentLandlordAccount $current, CreateProperty $createProperty): RedirectResponse
    {
        $property = $createProperty->handle($current->account(), $request->details());

        return redirect()
            ->route('landlord.properties.show', $property->id)
            ->with('status', __(':name is saved as a draft. Add its rooms next.', ['name' => $property->name]));
    }
}
