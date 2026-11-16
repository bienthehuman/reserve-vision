<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProofStatus;
use App\Enums\ProofFileType;
use App\Models\Concerns\BelongsToLandlordAccount;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentProofFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A renter's proof of paying the reservation fee (slice 5.3). The file is kept exactly as uploaded
 * (it's evidence) on the private `proofs` disk at an unguessable `path`, and is only ever served
 * through `/proofs/{proof}` and its short-lived signed URL (docs/ACCESS.md "Private files").
 *
 * @property int $id
 * @property int $reservation_id
 * @property int $landlord_account_id
 * @property string $path
 * @property ProofFileType $mime_type
 * @property int $amount
 * @property string|null $reference_no
 * @property PaymentProofStatus $status
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $rejection_reason
 * @property CarbonImmutable $uploaded_at
 */
#[Fillable([
    'reservation_id', 'landlord_account_id', 'path', 'mime_type', 'amount', 'reference_no', 'status',
    'reviewed_by', 'reviewed_at', 'rejection_reason', 'uploaded_at',
])]
final class PaymentProof extends Model
{
    /** @use HasFactory<PaymentProofFactory> */
    use BelongsToLandlordAccount, HasFactory;

    /** The private disk (config/filesystems.php): local in dev and tests, a private bucket in production. */
    public const string DISK = 'proofs';

    public $timestamps = false;

    /** A new random path for a file of `$type`: `proofs/<uuid>.<ext>` (122 random bits, so unguessable). */
    public static function newPath(ProofFileType $type): string
    {
        return 'proofs/'.Str::uuid()->toString().'.'.$type->extension();
    }

    /** The name a download is offered under: `proof-<id>.<ext>` (never the renter's own file name). */
    public function downloadName(): string
    {
        return "proof-{$this->id}.{$this->mime_type->extension()}";
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mime_type' => ProofFileType::class,
            'amount' => 'integer',
            'status' => PaymentProofStatus::class,
            'reviewed_at' => 'immutable_datetime',
            'uploaded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
