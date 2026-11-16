<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\ChecksInvitees;
use App\Data\NewInvitation;
use App\Enums\LandlordRole;
use App\Exceptions\Domain\CannotInvite;
use App\Models\LandlordAccount;
use App\Models\LandlordInvitation;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invites a caretaker to the current account (owner only: LandlordInvitationPolicy::create,
 * checked by the controller). The token is random, stored as a SHA-256 hash, and
 * valid for 7 days; the plain token leaves in the returned NewInvitation, which the
 * controller puts in the invitation email. One audit row, `invitation.created`.
 *
 * Rejected: an email that already belongs to a member of any account, an email
 * of a renter (a person is one type, DATA_MODEL.md), and a second pending
 * invitation for the same email on this account. The account row is locked so
 * two owners inviting the same email at once can't both pass the last check.
 */
final class CreateInvitation
{
    use ChecksInvitees;

    public const int VALID_DAYS = 7;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotInvite */
    public function handle(LandlordAccount $account, User $inviter, string $email): NewInvitation
    {
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($account, $inviter, $email): NewInvitation {
            LandlordAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            $this->assertEmailCanBeInvited($email);

            $pending = LandlordInvitation::query()
                ->where('landlord_account_id', $account->id)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            if ($pending) {
                throw CannotInvite::alreadyPending();
            }

            $token = Str::random(48);

            $invitation = LandlordInvitation::create([
                'landlord_account_id' => $account->id,
                'email' => $email,
                'role' => LandlordRole::Caretaker,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ]);

            $this->audit->record('invitation.created', $invitation, [], [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'token_hash' => $invitation->token_hash,
                'expires_at' => $invitation->expires_at,
            ]);

            return new NewInvitation($invitation, $token);
        });
    }
}
