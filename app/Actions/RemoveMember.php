<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LandlordRole;
use App\Exceptions\Domain\CannotRemoveMember;
use App\Models\LandlordMember;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Removes a caretaker or another owner from the account (owner only: LandlordMemberPolicy::remove,
 * checked by the controller). The person keeps their login, but with no membership every landlord
 * page answers 403 (the isolation scope fails closed); they can be invited again.
 *
 * Every member row of the account is locked first (`FOR UPDATE`, by id), and the rules are read
 * from those rows: the actor must STILL be an owner (two owners removing each other at once: the
 * second finds they've been removed), nobody removes themself, and the account keeps at least one
 * owner. Already removed: nothing happens. One audit row, `member.removed`.
 */
final class RemoveMember
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotRemoveMember */
    public function handle(LandlordMember $member, User $actor): void
    {
        DB::transaction(function () use ($member, $actor): void {
            $members = LandlordMember::query()
                ->where('landlord_account_id', $member->landlord_account_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $actorRow = $members->firstWhere('user_id', $actor->id);

            if ($actorRow === null || $actorRow->role !== LandlordRole::Owner) {
                throw CannotRemoveMember::notAnOwnerAnyMore();
            }

            $target = $members->firstWhere('id', $member->id);

            if ($target === null) {
                return;
            }

            if ($target->user_id === $actor->id) {
                throw CannotRemoveMember::yourself();
            }

            $otherOwners = $members->filter(fn (LandlordMember $row): bool => $row->role === LandlordRole::Owner && $row->id !== $target->id);

            if ($otherOwners->isEmpty()) {
                throw CannotRemoveMember::lastOwner();
            }

            $target->delete();

            $this->audit->record('member.removed', $target, ['user_id' => $target->user_id, 'role' => $target->role], []);
        });
    }
}
