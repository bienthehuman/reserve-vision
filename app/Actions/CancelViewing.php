<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ViewingRequestStatus;
use App\Exceptions\Domain\InvalidViewingMove;
use App\Models\User;
use App\Models\ViewingRequest;
use Illuminate\Support\Facades\DB;

/**
 * The renter cancels their own viewing request while it is requested or accepted, until the preferred
 * time (docs/ACCESS.md flow C). Who may: ViewingRequestPolicy::cancel. No audit row (a renter action);
 * telling the landlord is slice 6.3's.
 */
final class CancelViewing
{
    /** @throws InvalidViewingMove */
    public function handle(User $renter, ViewingRequest $viewing): ViewingRequest
    {
        return DB::transaction(function () use ($renter, $viewing): ViewingRequest {
            $locked = ViewingRequest::query()->where('viewing_requests.renter_id', $renter->id)
                ->whereKey($viewing->getKey())->lockForUpdate()->firstOrFail();
            $locked->assertCanMoveTo(ViewingRequestStatus::Cancelled);

            $locked->update(['status' => ViewingRequestStatus::Cancelled]);

            return $locked;
        });
    }
}
