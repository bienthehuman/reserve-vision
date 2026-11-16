<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Models\Property;
use App\Support\PropertyFormProps;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** GET /landlord/properties/{property}/edit. A caretaker gets 403, another account's property a 404. */
final class EditProperty
{
    public function __invoke(Property $property): Response
    {
        Gate::authorize('update', $property);

        return Inertia::render('Landlord/Properties/Form', PropertyFormProps::for($property));
    }
}
