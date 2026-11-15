<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Models\LandlordAccount;
use App\Models\LandlordMember;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Placeholder until phase 4: the account's name, the user's role and "awaiting verification" (ACCESS.md, flow A). */
final class DashboardController
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        /** @var LandlordMember $member */
        $member = $user->landlordMember()->with('landlordAccount')->firstOrFail();
        /** @var LandlordAccount $account landlord_members.landlord_account_id is a required foreign key */
        $account = $member->landlordAccount;

        return Inertia::render('Landlord/Dashboard', [
            'account' => [
                'name' => $account->name,
                'slug' => $account->slug,
                'isVerified' => $account->is_verified,
            ],
            'role' => $member->role->value,
        ]);
    }
}
