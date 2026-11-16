<?php

declare(strict_types=1);

namespace App\Http\Controllers\Proofs;

use App\Models\PaymentProof;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * `GET /proofs/{proof}` (docs/ACCESS.md "Private files"): the one link pages use for a proof. Asks
 * PaymentProofPolicy::view (the renter whose reservation it is, or a member of its account; anyone
 * else 404), then redirects to a signed URL for DownloadProof that is valid for 5 minutes and names
 * the proof AND the user it was made for. A landlord's lookup goes through the isolation scope, so
 * another account's proof is a 404 before the policy is even asked.
 */
final class ShowProof
{
    public const int VALID_MINUTES = 5;

    public function __invoke(Request $request, int $proof): RedirectResponse
    {
        /** @var User $user (auth middleware) */
        $user = $request->user();

        $proof = PaymentProof::query()->findOrFail($proof);
        Gate::authorize('view', $proof);

        $url = URL::temporarySignedRoute(
            'proofs.file',
            Date::now()->addMinutes(self::VALID_MINUTES),
            ['proof' => $proof->id, DownloadProof::USER_PARAMETER => $user->id],
            absolute: false,
        );

        return redirect()->to($url)->header('Cache-Control', 'private, no-store');
    }
}
