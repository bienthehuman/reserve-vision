<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\DecidesViewings;
use App\Enums\ViewingRequestStatus;
use App\Exceptions\Domain\InvalidViewingMove;
use App\Mail\Viewings\ViewingAnswered;
use App\Models\User;
use App\Models\ViewingRequest;
use App\Support\AuditLogger;
use App\Support\Mail\Notify;

/**
 * A landlord member accepts a viewing request, or changes a decline into an accept (until the preferred time) (docs/ACCESS.md flow C).
 * Who may: ViewingRequestPolicy::answer (owner, caretaker). The optional note is what the renter reads;
 * it replaces an earlier one. One audit row, `viewing.accepted`. The renter is emailed after the transaction, with the note.
 */
final class AcceptViewing
{
    use DecidesViewings;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws InvalidViewingMove when the status or the clock doesn't allow it */
    public function handle(User $by, ViewingRequest $viewing, ?string $note = null): ViewingRequest
    {
        $viewing = $this->move($by, $viewing, ViewingRequestStatus::Accepted, $note, 'viewing.accepted');

        Notify::renter($viewing->renter_id, new ViewingAnswered($viewing->id, accepted: true));

        return $viewing;
    }
}
