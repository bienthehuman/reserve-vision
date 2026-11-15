<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProofStatus;
use App\Models\Concerns\BelongsToLandlordAccount;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentProofFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $reservation_id
 * @property int $landlord_account_id
 * @property string $path
 * @property int $amount
 * @property string|null $reference_no
 * @property PaymentProofStatus $status
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $rejection_reason
 * @property CarbonImmutable $uploaded_at
 */
#[Fillable([
    'reservation_id', 'landlord_account_id', 'path', 'amount', 'reference_no', 'status',
    'reviewed_by', 'reviewed_at', 'rejection_reason', 'uploaded_at',
])]
final class PaymentProof extends Model
{
    /** @use HasFactory<PaymentProofFactory> */
    use BelongsToLandlordAccount, HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
