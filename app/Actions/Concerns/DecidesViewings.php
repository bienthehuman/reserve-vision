<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Enums\ViewingRequestStatus;
use App\Exceptions\Domain\InvalidViewingMove;
use App\Models\User;
use App\Models\ViewingRequest;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * What every landlord move on a viewing request does (slice 6.2, docs/ACCESS.md flow C): lock the row
 * FOR UPDATE and re-read it through the isolation scope (only the locked row's status counts, never the
 * model the controller bound), check the transition table and the clock on it (`assertCanMoveTo()`), write
 * the change and one audit row, all in one transaction. A viewing needs no room or renter lock: no move
 * here can make another request open.
 *
 * @property-read AuditLogger $audit
 */
trait DecidesViewings
{
    /** @throws InvalidViewingMove */
    private function move(User $by, ViewingRequest $viewing, ViewingRequestStatus $to, ?string $note, string $auditAction, bool $replaceNote = true): ViewingRequest
    {
        return DB::transaction(function () use ($by, $viewing, $to, $note, $auditAction, $replaceNote): ViewingRequest {
            $locked = ViewingRequest::query()->whereKey($viewing->getKey())->lockForUpdate()->firstOrFail();
            $locked->assertCanMoveTo($to);

            $fields = ['status', 'landlord_note', 'decided_by'];
            $before = $this->snapshot($locked, $fields);

            $locked->update([
                'status' => $to,
                'decided_by' => $by->id,
                ...($replaceNote ? ['landlord_note' => $this->clean($note)] : []),
            ]);

            $this->audit->record($auditAction, $locked, $before, $this->snapshot($locked, $fields));

            return $locked;
        });
    }

    private function clean(?string $note): ?string
    {
        $note = $note === null ? '' : trim($note);

        return $note === '' ? null : $note;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function snapshot(ViewingRequest $viewing, array $fields): array
    {
        return array_combine($fields, array_map(fn (string $field): mixed => $viewing->getAttribute($field), $fields));
    }
}
