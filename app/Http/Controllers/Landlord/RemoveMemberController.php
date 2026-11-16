<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\RemoveMember;
use App\Models\LandlordMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * DELETE /landlord/members/{member}: owners only. `landlord_members` has no isolation scope
 * (the scope itself reads it), so the policy is what makes another account's member a 404.
 */
final class RemoveMemberController
{
    public function __invoke(Request $request, LandlordMember $member, RemoveMember $remove): RedirectResponse|JsonResponse
    {
        Gate::authorize('remove', $member);

        /** @var User $actor (auth + landlord middleware) */
        $actor = $request->user();
        $name = $member->user->full_name;

        $remove->handle($member, $actor);

        $message = __(':name is removed from this account.', ['name' => $name]);

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : redirect()->route('landlord.members.index')->with('status', $message);
    }
}
