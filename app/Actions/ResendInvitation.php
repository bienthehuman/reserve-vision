<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\ChecksInvitees;
use App\Data\NewInvitation;
use App\Exceptions\Domain\CannotInvite;
use App\Models\LandlordInvitation;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sends an unanswered invitation again (owner only: LandlordInvitationPolicy::resend, checked by
 * the controller): a NEW token, so the old link stops working, and 7 more days from now. An
 * expired invitation comes back to life this way. Refused once it is accepted or revoked, and if
 * the email has since become a renter's or another account's member. The invitation row is locked,
 * the same lock AcceptInvitation takes. One audit row, `invitation.resent`.
 */
final class ResendInvitation
{
    use ChecksInvitees;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotInvite */
    public function handle(LandlordInvitation $invitation): NewInvitation
    {
        return DB::transaction(function () use ($invitation): NewInvitation {
            $invitation = LandlordInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            if ($invitation->accepted_at !== null) {
                throw CannotInvite::alreadyAccepted();
            }

            if ($invitation->revoked_at !== null) {
                throw CannotInvite::alreadyRevoked();
            }

            $this->assertEmailCanBeInvited($invitation->email);

            $before = ['token_hash' => $invitation->token_hash, 'expires_at' => $invitation->expires_at];
            $token = Str::random(48);
            $invitation->token_hash = hash('sha256', $token);
            $invitation->expires_at = now()->toImmutable()->addDays(CreateInvitation::VALID_DAYS);
            $invitation->save();

            $this->audit->record('invitation.resent', $invitation, $before, [
                'token_hash' => $invitation->token_hash,
                'expires_at' => $invitation->expires_at,
            ]);

            return new NewInvitation($invitation, $token);
        });
    }
}
