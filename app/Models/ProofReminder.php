<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * "The landlord's members were reminded about this proof after N hours" (slice 6.3). System bookkeeping, not
 * landlord-owned data: written only by `RemindAboutProof`, from the scheduled job, so it has no isolation scope.
 *
 * @property int $id
 * @property int $payment_proof_id
 * @property int $threshold_hours
 * @property CarbonImmutable $sent_at
 */
#[Fillable(['payment_proof_id', 'threshold_hours', 'sent_at'])]
final class ProofReminder extends Model
{
    public $timestamps = false;

    /** The reminders go out after these many hours, each once (RESERVATIONS.md "Unanswered proofs"). */
    public const array THRESHOLDS = [24, 48];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold_hours' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
