<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Models\Property;
use App\Support\PropertyFormProps;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** GET /landlord/properties/create: the empty property form. A caretaker gets 403. */
final class NewProperty
{
    public function __invoke(): Response
    {
        Gate::authorize('create', Property::class);

        return Inertia::render('Landlord/Properties/Form', PropertyFormProps::for(null));
    }
}
