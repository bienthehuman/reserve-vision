<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PaymentProofStatus;
use App\Enums\ProofFileType;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\HoldExpired;
use App\Exceptions\Domain\InvalidTransition;
use App\Models\PaymentProof;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * The renter sends proof of paying the reservation fee: `held` → `proof_uploaded`, on a live hold
 * only (docs/RESERVATIONS.md, T5). The request has already checked the file by its content
 * (App\Rules\PaymentProofFile) and detected `$type`; who may call this is PaymentProofPolicy::upload.
 *
 * One transaction: lock the reservation row, assertCanMoveTo() on it, write the file to the private
 * disk, insert the `pending` proof and move the reservation. The bytes are stored exactly as
 * uploaded (evidence), under a random path. Renter actions write no audit row (CONVENTIONS.md);
 * emailing the landlord members is slice 6.3's.
 */
final class UploadPaymentProof
{
    /**
     * @throws HoldExpired when the hold has run out, whether or not ExpireHolds has run
     * @throws InvalidTransition when the reservation isn't `held` any more
     */
    public function handle(User $renter, Reservation $reservation, SplFileInfo $file, ProofFileType $type, int $amount, string $referenceNo): PaymentProof
    {
        return DB::transaction(function () use ($renter, $reservation, $file, $type, $amount, $referenceNo): PaymentProof {
            // The lock first, and only the locked row's status counts (a stale model could still say `held`).
            $locked = Reservation::query()->ownedByRenter($renter)->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();
            $locked->assertCanMoveTo(ReservationStatus::ProofUploaded);

            $path = PaymentProof::newPath($type);
            $this->store($path, $file);

            try {
                $proof = PaymentProof::create([
                    'reservation_id' => $locked->id,
                    'landlord_account_id' => $locked->landlord_account_id,
                    'path' => $path,
                    'mime_type' => $type,
                    'amount' => $amount,
                    'reference_no' => $referenceNo,
                    'status' => PaymentProofStatus::Pending,
                    'uploaded_at' => Date::now(),
                ]);

                $locked->update(['status' => ReservationStatus::ProofUploaded]);
            } catch (Throwable $e) {
                rescue(fn () => Storage::disk(PaymentProof::DISK)->delete($path));

                throw $e;
            }

            return $proof;
        });
    }

    /** The original bytes, streamed to the private disk; nothing is re-encoded. */
    private function store(string $path, SplFileInfo $file): void
    {
        $stream = fopen($file->getPathname(), 'rb');

        if ($stream === false) {
            throw new RuntimeException("Could not read the uploaded file [{$file->getPathname()}].");
        }

        try {
            Storage::disk(PaymentProof::DISK)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
