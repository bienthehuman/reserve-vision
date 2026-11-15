<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Models\Property;
use App\Support\CurrentLandlordAccount;
use Inertia\Inertia;
use Inertia\Response;

/** Placeholder until phase 4: the account's name, the user's role and "awaiting verification" (ACCESS.md, flow A). */
final class DashboardController
{
    public function __invoke(CurrentLandlordAccount $current): Response
    {
        $account = $current->account();
        Property::query()->withoutGlobalScopes()->count();

        return Inertia::render('Landlord/Dashboard', [
            'account' => [
                'name' => $account->name,
                'slug' => $account->slug,
                'isVerified' => $account->is_verified,
            ],
            'role' => $current->role()->value,
        ]);
    }
}
