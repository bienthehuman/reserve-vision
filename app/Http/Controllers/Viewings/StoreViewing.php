<?php

declare(strict_types=1);

namespace App\Http\Controllers\Viewings;

use App\Actions\RequestViewing;
use App\Http\Requests\RequestViewingRequest;
use App\Models\Property;
use App\Models\User;
use App\Models\ViewingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * POST /viewings: a renter asks to see a listed property (`property_id`, `date`, `time`, `message`). A property that
 * isn't listed is a 404 (as its page is). The rules are RequestViewing's: a refusal is the `error` flash back on the
 * listing, or 422 JSON. Done, the renter lands on their list, with the confirmation.
 */
final class StoreViewing
{
    public function __invoke(RequestViewingRequest $request, RequestViewing $requestViewing): RedirectResponse|JsonResponse
    {
        $property = Property::query()->published()->findOrFail($request->propertyId());
        Gate::authorize('create', [ViewingRequest::class, $property]);

        /** @var User $renter (auth + renter middleware) */
        $renter = $request->user();

        $viewing = $requestViewing->handle($renter, $property, $request->preferredAt(), $request->note());

        if ($request->expectsJson()) {
            return response()->json([
                'viewing' => ['id' => $viewing->id, 'status' => $viewing->status->value, 'preferred_at' => $viewing->preferred_at->toIso8601String()],
                'url' => route('viewings.index'),
            ], 201);
        }

        return redirect()->route('viewings.index')
            ->with('status', __('Viewing requested. :property will answer soon; you can change your mind until the time comes.', ['property' => $property->name]));
    }
}
