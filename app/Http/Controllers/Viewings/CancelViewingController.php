<?php

declare(strict_types=1);

namespace App\Http\Controllers\Viewings;

use App\Actions\CancelViewing;
use App\Models\User;
use App\Models\ViewingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /viewings/{viewing}/cancel`. Only the renter's own request (another renter's is a 404, found by renter
 * and id); whether its status and the clock still allow it is CancelViewing's, on the locked row.
 */
final class CancelViewingController
{
    public function __invoke(Request $request, int $viewing, CancelViewing $cancel): RedirectResponse
    {
        /** @var User $renter (auth + renter middleware) */
        $renter = $request->user();

        $viewing = ViewingRequest::query()->where('viewing_requests.renter_id', $renter->id)->findOrFail($viewing);
        Gate::authorize('cancel', $viewing);

        $cancel->handle($renter, $viewing);

        return redirect()->route('viewings.index')->with('status', __('Your viewing request is cancelled.'));
    }
}
