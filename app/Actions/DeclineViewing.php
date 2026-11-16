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
 * A landlord member declines a viewing request, or changes an accept into a decline (until the preferred time) (docs/ACCESS.md flow C).
 * Who may: ViewingRequestPolicy::answer (owner, caretaker). The optional note is what the renter reads;
 * it replaces an earlier one. One audit row, `viewing.declined`. Emailing the renter is slice 6.3's.
 */
final class DeclineViewing
{
    use DecidesViewings;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws InvalidViewingMove when the status or the clock doesn't allow it */
    public function handle(User $by, ViewingRequest $viewing, ?string $note = null): ViewingRequest
    {
        return $this->move($by, $viewing, ViewingRequestStatus::Declined, $note, 'viewing.declined');
    }
}
