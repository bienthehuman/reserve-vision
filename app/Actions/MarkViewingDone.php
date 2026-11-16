<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\DecidesViewings;
use App\Enums\ViewingRequestStatus;
use App\Exceptions\Domain\InvalidViewingMove;
use App\Models\User;
use App\Models\ViewingRequest;
use App\Support\AuditLogger;

/**
 * A landlord member marks an accepted viewing done, from its preferred time on (docs/ACCESS.md flow C).
 * Who may: ViewingRequestPolicy::markDone. The note stays as it was. One audit row, `viewing.marked_done`.
 */
final class MarkViewingDone
{
    use DecidesViewings;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws InvalidViewingMove before the preferred time, or from any status but accepted */
    public function handle(User $by, ViewingRequest $viewing): ViewingRequest
    {
        return $this->move($by, $viewing, ViewingRequestStatus::Done, null, 'viewing.marked_done', replaceNote: false);
    }
}
