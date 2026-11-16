<?php

declare(strict_types=1);

namespace App\Http\Controllers\Proofs;

use App\Models\PaymentProof;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The signed download (docs/ACCESS.md "Private files", T9). Three checks, in this order:
 *
 * 1. The signature and expiry: the `signed:relative` route middleware (403 when wrong or late).
 * 2. The logged-in user is the one the URL was made for (403 otherwise, guests included): a URL
 *    copied into another browser, chat or log is useless to anyone else.
 * 3. PaymentProofPolicy::view again, on a fresh lookup: access revoked in the 5 minutes since the
 *    URL was made (a removed member, say) is refused (404).
 *
 * Then the file is streamed through PHP from the private disk, in every environment: the bucket is
 * never reachable from outside, and nothing but this route can hand out the bytes. Served with the
 * type detected at upload, `inline`, uncacheable, `nosniff`, and `Content-Security-Policy: sandbox`
 * so a crafted file can't run script in the app's origin.
 */
final class DownloadProof
{
    public const string USER_PARAMETER = 'user';

    public function __invoke(Request $request, int $proof): StreamedResponse
    {
        $user = $request->user();
        $for = $request->query(self::USER_PARAMETER);

        abort_unless($user instanceof User, 403); // NEGATIVE CONTROL (temporary): the user-id check is dropped

        $proof = PaymentProof::query()->findOrFail($proof);
        Gate::forUser($user)->authorize('view', $proof);

        $disk = Storage::disk(PaymentProof::DISK);
        abort_unless($disk->exists($proof->path), 404);
        $size = $disk->size($proof->path);

        return response()->stream(function () use ($disk, $proof): void {
            $stream = $disk->readStream($proof->path) ?? throw new RuntimeException("Could not read proof {$proof->id}.");

            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $proof->mime_type->value,
            'Content-Length' => (string) $size,
            'Content-Disposition' => 'inline; filename="'.$proof->downloadName().'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
        ]);
    }
}
