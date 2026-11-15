<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Models\LandlordInvitation;
use App\Models\LandlordMember;
use App\Support\CurrentLandlordAccount;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Members and pending invitations of the current account (owners only: it is
 * the page for "Invite or remove caretakers, revoke invites"). `landlord_members`
 * has no isolation scope (the scope reads it), so the account is filtered here,
 * from the current account and nothing in the request. Invitations are scoped.
 */
final class ListMembers
{
    public function __invoke(CurrentLandlordAccount $current): Response
    {
        Gate::authorize('viewAny', LandlordInvitation::class);

        $members = LandlordMember::query()
            ->where('landlord_account_id', $current->id())
            ->with('user')
            ->oldest('id')
            ->get();

        $invitations = LandlordInvitation::query()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->latest('id')
            ->get();

        return Inertia::render('Landlord/Members/Index', [
            'members' => $members->map(fn (LandlordMember $member): array => [
                'id' => $member->id,
                'name' => $member->user->full_name,
                'role' => $member->role->value,
                'joined_at' => $member->created_at->toIso8601String(),
            ])->all(),
            'invitations' => $invitations->map(fn (LandlordInvitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'is_expired' => $invitation->expires_at->isPast(),
            ])->all(),
        ]);
    }
}
