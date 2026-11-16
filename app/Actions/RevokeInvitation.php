<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\Domain\CannotInvite;
use App\Models\LandlordInvitation;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Takes an invitation back (owner only: LandlordInvitationPolicy::revoke, checked by the
 * controller): its link stops working at once. Refused once it is accepted (remove the member
 * instead); already revoked: nothing happens. The invitation row is locked, the same lock
 * AcceptInvitation takes, so a revoke and an accept can't both win. One audit row, `invitation.revoked`.
 */
final class RevokeInvitation
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotInvite */
    public function handle(LandlordInvitation $invitation): LandlordInvitation
    {
        return DB::transaction(function () use ($invitation): LandlordInvitation {
            $invitation = LandlordInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            if ($invitation->accepted_at !== null) {
                throw CannotInvite::alreadyAccepted();
            }

            if ($invitation->revoked_at !== null) {
                return $invitation;
            }

            $invitation->revoked_at = now()->toImmutable();
            $invitation->save();

            $this->audit->record('invitation.revoked', $invitation, ['revoked_at' => null], ['revoked_at' => $invitation->revoked_at]);

            return $invitation;
        });
    }
}
