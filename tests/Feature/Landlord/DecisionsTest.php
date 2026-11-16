<?php

declare(strict_types=1);

use App\Actions\ConfirmReservation;
use App\Actions\DeclineReservation;
use App\Actions\EndStay;
use App\Actions\MarkMovedIn;
use App\Actions\RecordRefund;
use App\Actions\RejectProof;
use App\Actions\UndoConfirmation;
use App\Actions\UploadPaymentProof;
use App\Actions\WithdrawReservation;
use App\Enums\PaymentProofStatus;
use App\Enums\ProofFileType;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\NoSpaceAvailable;
use App\Models\PaymentProof;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Support\Search\ListingSearch;
use App\Support\Search\SearchFilters;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/*
| Slice 6.1: the landlord's moves in the reservation lifecycle (docs/RESERVATIONS.md "Transition table").
| "Now" is 2027-03-15 10:00 Manila, so today is Mar 15 and the default move-in, Apr 1, is ahead.
*/

beforeEach(function (): void {
    fakeMediaDisks();
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

function asRole(array $world, string $role): User
{
    return $world[$role];
}

/** The reservation's one proof, fresh. */
function soleProof(Reservation $reservation): PaymentProof
{
    return PaymentProof::query()->where('reservation_id', $reservation->id)->sole();
}

/** Whether a new renter can hold the room for the reservation's dates, through the 5.2 search and ReserveSpace. */
function roomIsFreeFor(Room $room, string $moveIn, int $months = 1): bool
{
    $search = ListingSearch::rooms(new SearchFilters(moveIn: CarbonImmutable::parse($moveIn), months: $months))->whereKey($room->id)->exists();

    try {
        reserve(User::factory()->renter()->create(), $room, $moveIn, $months);
        $reserved = true;
    } catch (NoSpaceAvailable) {
        $reserved = false;
    }

    expect($search)->toBe($reserved, 'the search and ReserveSpace disagree about the room');

    return $reserved;
}

/*
| Confirm
*/

it('confirms a proof: confirmed, decided by the member, the proof accepted, one audit row', function (string $role): void {
    $w = toDecide('proofUploaded');
    $actor = asRole($w, $role);

    $this->actingAs($actor)->post("/landlord/reservations/{$w['reservation']->id}/confirm")
        ->assertRedirect("/landlord/reservations/{$w['reservation']->id}")
        ->assertSessionHas('status', 'Reservation confirmed. The renter can move in on the agreed date.');

    $fresh = $w['reservation']->fresh();
    $proof = soleProof($w['reservation']);
    expect($fresh->status)->toBe(ReservationStatus::Confirmed)
        ->and($fresh->decided_by)->toBe($actor->id)
        ->and($fresh->decided_at?->equalTo(now()))->toBeTrue()
        ->and($proof->status)->toBe(PaymentProofStatus::Accepted)
        ->and($proof->reviewed_by)->toBe($actor->id)
        ->and($proof->reviewed_at?->equalTo(now()))->toBeTrue();

    $row = auditRows()->sole();
    expect($row->action)->toBe('reservation.confirmed')
        ->and($row->actor_id)->toBe($actor->id)
        ->and($row->subject_id)->toBe($w['reservation']->id)
        ->and(json_decode($row->before, true))->toEqual(['status' => 'proof_uploaded', 'decided_by' => null, 'decided_at' => null, 'proof_status' => 'pending'])
        ->and(json_decode($row->after, true))->toEqual(['status' => 'confirmed', 'decided_by' => $actor->id, 'decided_at' => now()->toIso8601String(), 'proof_status' => 'accepted']);
})->with(['owner', 'caretaker']);

it('confirms a proof sent while the hold was live, even after the hold time has passed: the payment keeps blocking', function (): void {
    $w = toDecide('proofUploaded', ['hold_expires_at' => now()->subDay()]);

    app(ConfirmReservation::class)->handle($w['owner'], $w['reservation']);

    expect($w['reservation']->fresh()->status)->toBe(ReservationStatus::Confirmed);
});

/*
| Reject proof
*/

it('rejects a proof: back to held with a new 24-hour hold, the proof rejected with the reason, one audit row', function (string $role): void {
    $w = toDecide('proofUploaded', ['hold_expires_at' => now()->subHours(3)]);
    $actor = asRole($w, $role);

    $this->actingAs($actor)->post("/landlord/reservations/{$w['reservation']->id}/reject", ['reason' => '  The amount is ₱1,000; the fee is ₱1,500.  '])
        ->assertRedirect("/landlord/reservations/{$w['reservation']->id}")
        ->assertSessionHas('status', 'Proof sent back. The renter has 24 hours to send a new one.');

    $fresh = $w['reservation']->fresh();
    $proof = soleProof($w['reservation']);
    expect($fresh->status)->toBe(ReservationStatus::Held)
        ->and($fresh->proof_rejections)->toBe(1)
        ->and($fresh->hold_expires_at?->equalTo(now()->addHours(24)))->toBeTrue()
        ->and($fresh->isLiveHold())->toBeTrue()
        ->and($proof->status)->toBe(PaymentProofStatus::Rejected)
        ->and($proof->rejection_reason)->toBe('The amount is ₱1,000; the fee is ₱1,500.')
        ->and($proof->reviewed_by)->toBe($actor->id);

    $row = auditRows()->sole();
    expect($row->action)->toBe('reservation.proof_rejected')
        ->and(json_decode($row->after, true))->toMatchArray([
            'status' => 'held', 'proof_rejections' => 1, 'proof_status' => 'rejected',
            'rejection_reason' => 'The amount is ₱1,000; the fee is ₱1,500.', 'hold_expires_at' => now()->addHours(24)->toIso8601String(),
        ]);
})->with(['owner', 'caretaker']);

it('lets the renter send a new proof after a rejection, at most twice; the third time the landlord must decide', function (): void {
    $w = toDecide('proofUploaded');
    $renter = $w['reservation']->renter;
    $upload = fn () => app(UploadPaymentProof::class)->handle(
        $renter, $w['reservation'], UploadedFile::fake()->createWithContent('r.png', photoBytes(400, 700, 'png')), ProofFileType::Png, 150000, 'REF-2',
    );

    foreach ([1, 2] as $round) {
        app(RejectProof::class)->handle($w['owner'], $w['reservation'], "Blurry, round {$round}.");
        $upload();
        expect($w['reservation']->fresh()->status)->toBe(ReservationStatus::ProofUploaded);
    }

    $this->actingAs($w['owner'])->from("/landlord/reservations/{$w['reservation']->id}")
        ->post("/landlord/reservations/{$w['reservation']->id}/reject", ['reason' => 'Still blurry.'])
        ->assertSessionHas('error', 'A new proof has already been asked for twice. Confirm this one or decline the reservation.');

    expect($w['reservation']->fresh()->status)->toBe(ReservationStatus::ProofUploaded)
        ->and($w['reservation']->fresh()->proof_rejections)->toBe(2)
        ->and(PaymentProof::query()->where('reservation_id', $w['reservation']->id)->pluck('status')->map->value->all())
        ->toEqualCanonicalizing(['rejected', 'rejected', 'pending'])
        ->and(auditRows('reservation.proof_rejected'))->toHaveCount(2);

    // Confirming the third proof still works.
    app(ConfirmReservation::class)->handle($w['owner'], $w['reservation']);
    expect($w['reservation']->fresh()->status)->toBe(ReservationStatus::Confirmed);
});

/*
| Decline
*/

it('declines: declined with the reason, the pending proof rejected with it, one audit row, the space free', function (string $role): void {
    $w = toDecide('proofUploaded');
    $actor = asRole($w, $role);

    expect(roomIsFreeFor($w['room'], '2027-04-01'))->toBeFalse();

    $this->actingAs($actor)->post("/landlord/reservations/{$w['reservation']->id}/decline", ['reason' => 'Women only, sorry.'])
        ->assertRedirect("/landlord/reservations/{$w['reservation']->id}")
        ->assertSessionHas('status', 'Reservation declined. The bed is free again.');

    $fresh = $w['reservation']->fresh();
    expect($fresh->status)->toBe(ReservationStatus::Declined)
        ->and($fresh->decision_reason)->toBe('Women only, sorry.')
        ->and($fresh->decided_by)->toBe($actor->id)
        ->and(soleProof($w['reservation'])->status)->toBe(PaymentProofStatus::Rejected)
        ->and(soleProof($w['reservation'])->rejection_reason)->toBe('Women only, sorry.')
        ->and(auditRows()->sole()->action)->toBe('reservation.declined')
        ->and(json_decode(auditRows()->sole()->after, true))->toMatchArray(['status' => 'declined', 'decision_reason' => 'Women only, sorry.', 'proof_status' => 'rejected']);

    $this->app['auth']->forgetGuards();
    expect(roomIsFreeFor($w['room'], '2027-04-01'))->toBeTrue();
})->with(['owner', 'caretaker']);

/*
| Undo
*/

it('undoes a confirmation within 24 hours: proof_uploaded again, the proof pending, the decision cleared', function (string $role): void {
    $w = toDecide('confirmed', ['decided_at' => now()->subHours(23)]);
    $actor = asRole($w, $role);

    $this->actingAs($actor)->post("/landlord/reservations/{$w['reservation']->id}/undo")
        ->assertRedirect("/landlord/reservations/{$w['reservation']->id}")
        ->assertSessionHas('status', 'Confirmation undone. The proof is waiting for your decision again.');

    $fresh = $w['reservation']->fresh();
    $proof = soleProof($w['reservation']);
    expect($fresh->status)->toBe(ReservationStatus::ProofUploaded)
        ->and($fresh->decided_by)->toBeNull()
        ->and($fresh->decided_at)->toBeNull()
        ->and($proof->status)->toBe(PaymentProofStatus::Pending)
        ->and($proof->reviewed_by)->toBeNull()
        ->and(auditRows()->sole()->action)->toBe('reservation.confirmation_undone')
        ->and(json_decode(auditRows()->sole()->after, true))->toMatchArray(['status' => 'proof_uploaded', 'proof_status' => 'pending']);
})->with(['owner', 'caretaker']);

it('undoes only while the 24 hours run, by the second', function (int $secondsAgo, bool $allowed): void {
    $w = toDecide('confirmed', ['decided_at' => now()->subSeconds($secondsAgo)]);

    $this->actingAs($w['owner'])->from("/landlord/reservations/{$w['reservation']->id}")
        ->post("/landlord/reservations/{$w['reservation']->id}/undo");

    expect($w['reservation']->fresh()->status)->toBe($allowed ? ReservationStatus::ProofUploaded : ReservationStatus::Confirmed);
    if (! $allowed) {
        expect(session('error'))->toBe('A confirmation can only be undone within 24 hours.')->and(auditRows())->toBeEmpty();
    }
})->with([
    'just confirmed' => [0, true],
    '1 second left' => [24 * 3600 - 1, true],
    'exactly 24 hours' => [24 * 3600, false],
    'a day and a bit' => [24 * 3600 + 60, false],
]);

it('refuses an undo on the move-in date (Manila), even within 24 hours', function (): void {
    // 2027-03-15 00:30 Manila is still Mar 14 in UTC: the business date decides.
    $this->travelTo(CarbonImmutable::parse('2027-03-15 00:30', 'Asia/Manila'));
    $w = toDecide('confirmed', ['decided_at' => now()->subHour()], moveIn: '2027-03-15');

    $this->actingAs($w['owner'])->from("/landlord/reservations/{$w['reservation']->id}")
        ->post("/landlord/reservations/{$w['reservation']->id}/undo")
        ->assertSessionHas('error', "The stay has started, so the confirmation can't be undone.");

    expect($w['reservation']->fresh()->status)->toBe(ReservationStatus::Confirmed);

    // The evening before, it still works.
    $this->app['auth']->forgetGuards();
    $this->travelTo(CarbonImmutable::parse('2027-03-14 23:59', 'Asia/Manila'));
    $v = toDecide('confirmed', ['decided_at' => now()->subHour()], moveIn: '2027-03-15');
    app(UndoConfirmation::class)->handle($v['reservation']);
    expect($v['reservation']->fresh()->status)->toBe(ReservationStatus::ProofUploaded);
});

/*
| Moved in
*/

it('marks moved in from the move-in date (Manila), not before', function (string $at, bool $allowed): void {
    $this->travelTo(CarbonImmutable::parse($at, 'Asia/Manila'));
    $w = toDecide('confirmed', moveIn: '2027-03-15');

    $this->actingAs($w['caretaker'])->from("/landlord/reservations/{$w['reservation']->id}")
        ->post("/landlord/reservations/{$w['reservation']->id}/moved-in");

    expect($w['reservation']->fresh()->status)->toBe($allowed ? ReservationStatus::Active : ReservationStatus::Confirmed);
    if ($allowed) {
        expect(session('status'))->toBe('Marked as moved in.')
            ->and(auditRows()->sole()->action)->toBe('reservation.moved_in')
            ->and(json_decode(auditRows()->sole()->after, true))->toBe(['status' => 'active']);
    } else {
        expect(session('error'))->toBe('The renter can be marked as moved in from Mon 15 Mar 2027.')->and(auditRows())->toBeEmpty();
    }
})->with([
    'the evening before' => ['2027-03-14 23:59', false],
    'just after midnight (still the 14th in UTC)' => ['2027-03-15 00:01', true],
    'a week later' => ['2027-03-22 12:00', true],
]);

/*
| Withdraw
*/

it('withdraws a confirmed reservation as an owner: cancelled by the owner, with the reason, the space free', function (): void {
    $w = toDecide('confirmed');

    expect(roomIsFreeFor($w['room'], '2027-04-01'))->toBeFalse();

    $this->actingAs($w['owner'])->post("/landlord/reservations/{$w['reservation']->id}/withdraw", ['reason' => 'The roof is being repaired.'])
        ->assertRedirect("/landlord/reservations/{$w['reservation']->id}")
        ->assertSessionHas('status', 'Reservation withdrawn. The bed is free again.');

    $fresh = $w['reservation']->fresh();
    expect($fresh->status)->toBe(ReservationStatus::Cancelled)
        ->and($fresh->cancelled_by)->toBe($w['owner']->id)
        ->and($fresh->cancelled_at?->equalTo(now()))->toBeTrue()
        ->and($fresh->decision_reason)->toBe('The roof is being repaired.')
        ->and($fresh->wasWithdrawn())->toBeTrue()
        ->and(auditRows()->sole()->action)->toBe('reservation.withdrawn');

    $this->app['auth']->forgetGuards();
    expect(roomIsFreeFor($w['room'], '2027-04-01'))->toBeTrue();
});

it('refuses a caretaker\'s withdraw with 403 before validating, and changes nothing', function (): void {
    $w = toDecide('confirmed');

    $this->actingAs($w['caretaker'])->post("/landlord/reservations/{$w['reservation']->id}/withdraw", ['reason' => 'No.'])->assertForbidden();
    $this->actingAs($w['caretaker'])->post("/landlord/reservations/{$w['reservation']->id}/withdraw", [])->assertForbidden();

    expect($w['reservation']->fresh()->status)->toBe(ReservationStatus::Confirmed)->and(auditRows())->toBeEmpty();
});

it('refuses a withdraw on or after the move-in date', function (): void {
    $w = toDecide('confirmed', moveIn: '2027-03-15');

    $this->actingAs($w['owner'])->from("/landlord/reservations/{$w['reservation']->id}")
        ->post("/landlord/reservations/{$w['reservation']->id}/withdraw", ['reason' => 'Too late.'])
        ->assertSessionHas('error', "This stay has already started, so it can't be cancelled here. Talk to the landlord.");

    expect($w['reservation']->fresh()->status)->toBe(ReservationStatus::Confirmed)->and(auditRows())->toBeEmpty();
});

/*
| End stay
*/

it('ends a stay early: move_out becomes today, and the space is free from today', function (string $role): void {
    $w = toDecide('active', moveIn: '2027-02-15');
    $actor = asRole($w, $role);

    expect(roomIsFreeFor($w['room'], '2027-03-15'))->toBeFalse();

    $this->actingAs($actor)->post("/landlord/reservations/{$w['reservation']->id}/end")
        ->assertRedirect("/landlord/reservations/{$w['reservation']->id}")
        ->assertSessionHas('status', 'Stay ended. The bed is free from the move-out date.');

    $fresh = $w['reservation']->fresh();
    expect($fresh->status)->toBe(ReservationStatus::Ended)
        ->and($fresh->move_out->toDateString())->toBe('2027-03-15')
        ->and(json_decode(auditRows()->sole()->before, true))->toEqual(['status' => 'active', 'move_out' => '2027-08-15T00:00:00+00:00'])
        ->and(json_decode(auditRows()->sole()->after, true))->toEqual(['status' => 'ended', 'move_out' => '2027-03-15T00:00:00+00:00']);

    $this->app['auth']->forgetGuards();
    expect(roomIsFreeFor($w['room'], '2027-03-15'))->toBeTrue();
})->with(['owner', 'caretaker']);

it('keeps a stay at least one night, and leaves a move_out that is already past', function (string $moveIn, string $moveOut, string $expected): void {
    $w = toDecide('active', ['move_out' => $moveOut], moveIn: $moveIn);

    app(EndStay::class)->handle($w['reservation']);

    expect($w['reservation']->fresh()->move_out->toDateString())->toBe($expected);
})->with([
    'moved in today' => ['2027-03-15', '2027-09-15', '2027-03-16'],
    'planned to leave yesterday' => ['2027-01-15', '2027-03-14', '2027-03-14'],
    'planned to leave tomorrow' => ['2027-01-15', '2027-03-16', '2027-03-15'],
]);

/*
| Refund
*/

it('records a refund on a declined or cancelled reservation that had a proof, and records a change again', function (string $state): void {
    $w = toDecide($state);
    if (PaymentProof::query()->where('reservation_id', $w['reservation']->id)->doesntExist()) {
        PaymentProof::factory()->for($w['reservation'])->create();
    }

    $this->actingAs($w['caretaker'])->put("/landlord/reservations/{$w['reservation']->id}/refund", ['fee_refunded' => '1', 'refund_note' => 'GCash, Mar 15.'])
        ->assertRedirect("/landlord/reservations/{$w['reservation']->id}")
        ->assertSessionHas('status', 'Refund record saved.');

    expect($w['reservation']->fresh()->fee_refunded)->toBeTrue()->and($w['reservation']->fresh()->refund_note)->toBe('GCash, Mar 15.');

    // The same values again: nothing changed, no row. Then a correction: one more row.
    $this->actingAs($w['caretaker'])->put("/landlord/reservations/{$w['reservation']->id}/refund", ['fee_refunded' => '1', 'refund_note' => 'GCash, Mar 15.']);
    $this->actingAs($w['owner'])->put("/landlord/reservations/{$w['reservation']->id}/refund", ['fee_refunded' => '0', 'refund_note' => '']);

    expect($w['reservation']->fresh()->fee_refunded)->toBeFalse()->and($w['reservation']->fresh()->refund_note)->toBeNull();
    $rows = auditRows('reservation.refund_recorded');
    expect($rows)->toHaveCount(2)
        ->and(json_decode($rows[0]->after, true))->toEqual(['fee_refunded' => true, 'refund_note' => 'GCash, Mar 15.'])
        ->and(json_decode($rows[1]->before, true))->toEqual(['fee_refunded' => true, 'refund_note' => 'GCash, Mar 15.'])
        ->and(json_decode($rows[1]->after, true))->toEqual(['fee_refunded' => false, 'refund_note' => null])
        ->and($rows[1]->actor_id)->toBe($w['owner']->id);
})->with(['declined', 'cancelled']);

it('refuses a refund record where nothing was paid or the reservation still runs', function (string $state, string $message): void {
    $w = toDecide($state);

    // The page sends it through Inertia (a redirect back with the `error` flash); as JSON it is the 422.
    $this->actingAs($w['owner'])->putJson("/landlord/reservations/{$w['reservation']->id}/refund", ['fee_refunded' => true])
        ->assertStatus(422)->assertExactJson(['message' => $message]);

    expect($w['reservation']->fresh()->fee_refunded)->toBeNull()->and(auditRows())->toBeEmpty();
})->with([
    'cancelled without a proof' => ['cancelled', 'The renter never sent a proof of payment, so there is no fee to refund.'],
    'confirmed' => ['confirmed', 'A refund can only be recorded once a reservation is declined or cancelled.'],
    'waiting for review' => ['proofUploaded', 'A refund can only be recorded once a reservation is declined or cancelled.'],
]);

it('validates the refund form', function (): void {
    $w = toDecide('declined');

    $this->actingAs($w['owner'])->from("/landlord/reservations/{$w['reservation']->id}")
        ->put("/landlord/reservations/{$w['reservation']->id}/refund", ['fee_refunded' => 'maybe', 'refund_note' => str_repeat('x', 1001)])
        ->assertSessionHasErrors(['fee_refunded', 'refund_note']);
    $this->actingAs($w['owner'])->from("/landlord/reservations/{$w['reservation']->id}")
        ->put("/landlord/reservations/{$w['reservation']->id}/refund", [])
        ->assertSessionHasErrors(['fee_refunded' => 'Choose whether the fee was refunded.']);

    expect(auditRows())->toBeEmpty();
});

/*
| Guards shared by every move
*/

it('refuses a move the transition table doesn\'t have from the status, and changes nothing', function (string $route, string $state, string $message): void {
    $w = toDecide($state);
    $before = $w['reservation']->fresh()->toArray();

    $this->actingAs($w['owner'])->from("/landlord/reservations/{$w['reservation']->id}")
        ->post("/landlord/reservations/{$w['reservation']->id}/{$route}", ['reason' => 'A reason.'])
        ->assertRedirect("/landlord/reservations/{$w['reservation']->id}")
        ->assertSessionHas('error', $message);

    expect($w['reservation']->fresh()->toArray())->toBe($before)->and(auditRows())->toBeEmpty();
})->with([
    'confirm a live hold' => ['confirm', 'held', "A reservation that is held can't become confirmed."],
    'confirm an expired hold' => ['confirm', 'expiredHold', "A reservation that is held can't become confirmed."],
    'reject an expired hold' => ['reject', 'expiredHold', "A reservation that is held can't become held."],
    'confirm twice' => ['confirm', 'confirmed', "A reservation that is confirmed can't become confirmed."],
    'decline a confirmed one' => ['decline', 'confirmed', "A reservation that is confirmed can't become declined."],
    'reject a cancelled one' => ['reject', 'cancelled', "A reservation that is cancelled can't become held."],
    'undo a proof waiting' => ['undo', 'proofUploaded', "A reservation that is proof_uploaded can't become proof_uploaded."],
    'move in a proof waiting' => ['moved-in', 'proofUploaded', "A reservation that is proof_uploaded can't become active."],
    'withdraw an active stay' => ['withdraw', 'active', "A reservation that is active can't become cancelled."],
    'end a confirmed stay' => ['end', 'confirmed', "A reservation that is confirmed can't become ended."],
    'end an ended stay' => ['end', 'ended', "A reservation that is ended can't become ended."],
    'decline a declined one' => ['decline', 'declined', "A reservation that is declined can't become declined."],
]);

it('requires a reason to reject, decline or withdraw', function (string $route, string $state): void {
    $w = toDecide($state);

    foreach ([[], ['reason' => '   '], ['reason' => str_repeat('x', 1001)]] as $body) {
        $this->actingAs($w['owner'])->from("/landlord/reservations/{$w['reservation']->id}")
            ->post("/landlord/reservations/{$w['reservation']->id}/{$route}", $body)
            ->assertSessionHasErrors('reason');
    }

    $this->actingAs($w['owner'])->from("/landlord/reservations/{$w['reservation']->id}")
        ->post("/landlord/reservations/{$w['reservation']->id}/{$route}", [])
        ->assertSessionHasErrors(['reason' => 'Write a reason. The renter will see it.']);

    expect($w['reservation']->fresh()->status)->toBe($w['reservation']->status)->and(auditRows())->toBeEmpty();
})->with([
    'reject' => ['reject', 'proofUploaded'],
    'decline' => ['decline', 'proofUploaded'],
    'withdraw' => ['withdraw', 'confirmed'],
]);

it('answers 404 for another account\'s reservation on every move, and 403 for a renter', function (string $method, string $route, string $state): void {
    $w = toDecide($state);
    $stranger = landlordUser();
    $renter = $w['reservation']->renter;
    $body = $route === 'refund' ? ['fee_refunded' => '1'] : ['reason' => 'A reason.'];

    $this->actingAs($stranger)->call($method, "/landlord/reservations/{$w['reservation']->id}/{$route}", $body)->assertNotFound();
    $this->actingAs($renter)->call($method, "/landlord/reservations/{$w['reservation']->id}/{$route}", $body)->assertForbidden();

    expect($w['reservation']->fresh()->status)->toBe($w['reservation']->status)->and(auditRows())->toBeEmpty();
})->with([
    ['POST', 'confirm', 'proofUploaded'],
    ['POST', 'reject', 'proofUploaded'],
    ['POST', 'decline', 'proofUploaded'],
    ['POST', 'undo', 'confirmed'],
    ['POST', 'moved-in', 'confirmed'],
    ['POST', 'withdraw', 'confirmed'],
    ['POST', 'end', 'active'],
    ['PUT', 'refund', 'declined'],
]);

it('locks the reservation row before anything else, in every action', function (Closure $act, string $state): void {
    $w = toDecide($state, moveIn: $state === 'confirmed' ? '2027-03-15' : ($state === 'active' ? '2027-02-15' : '2027-04-01'));
    $this->actingAs($w['owner']);
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        // The membership lookup is the isolation scope's (who is logged in), not the action's.
        if (! str_contains($query->sql, 'from `landlord_members`') && ! str_contains($query->sql, 'from `landlord_accounts`')) {
            $statements[] = $query->sql;
        }
    });

    $act($w);

    expect($statements[0])->toContain('from `reservations`')->toContain('for update')
        ->and(collect($statements)->filter(fn (string $sql): bool => str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'payment_proofs'))
            ->every(fn (string $sql): bool => str_contains($sql, 'for update') || str_contains($sql, 'lock in share mode')))->toBeTrue('a read of the proofs is not a locking read');
})->with([
    'confirm' => [fn (array $w) => app(ConfirmReservation::class)->handle($w['owner'], $w['reservation']), 'proofUploaded'],
    'reject' => [fn (array $w) => app(RejectProof::class)->handle($w['owner'], $w['reservation'], 'Blurry.'), 'proofUploaded'],
    'decline' => [fn (array $w) => app(DeclineReservation::class)->handle($w['owner'], $w['reservation'], 'No.'), 'proofUploaded'],
    'moved in' => [fn (array $w) => app(MarkMovedIn::class)->handle($w['reservation']), 'confirmed'],
    'end stay' => [fn (array $w) => app(EndStay::class)->handle($w['reservation']), 'active'],
    'refund' => [fn (array $w) => app(RecordRefund::class)->handle($w['reservation'], true, null), 'declined'],
]);

it('locks the reservation row first for undo and withdraw too', function (): void {
    $undo = toDecide('confirmed');
    $withdraw = toDecide('confirmed');
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        // The membership lookup is the isolation scope's (who is logged in), not the action's.
        if (! str_contains($query->sql, 'from `landlord_members`') && ! str_contains($query->sql, 'from `landlord_accounts`')) {
            $statements[] = $query->sql;
        }
    });

    $this->actingAs($undo['owner']);
    app(UndoConfirmation::class)->handle($undo['reservation']);
    expect($statements[0])->toContain('from `reservations`')->toContain('for update');

    $statements = [];
    $this->app['auth']->forgetGuards();
    $this->actingAs($withdraw['owner']);
    app(WithdrawReservation::class)->handle($withdraw['owner'], $withdraw['reservation'], 'Repairs.');
    expect($statements[0])->toContain('from `reservations`')->toContain('for update');
});

/*
| Blocking: a confirmed, re-held or undone reservation keeps blocking; declined, cancelled and ended free the space.
*/

it('keeps the space blocked through confirm, reject and undo, against ReserveSpace and the search', function (): void {
    $w = toDecide('proofUploaded');

    app(ConfirmReservation::class)->handle($w['owner'], $w['reservation']);
    expect(roomIsFreeFor($w['room'], '2027-04-01'))->toBeFalse();

    app(UndoConfirmation::class)->handle($w['reservation']);
    expect(roomIsFreeFor($w['room'], '2027-04-01'))->toBeFalse();

    app(RejectProof::class)->handle($w['owner'], $w['reservation'], 'Blurry.');
    expect(roomIsFreeFor($w['room'], '2027-04-01'))->toBeFalse();

    // The new hold blocks until it runs out, like any hold.
    $this->travel(24)->hours();
    expect(roomIsFreeFor($w['room'], '2027-04-01'))->toBeTrue();
});

it('keeps an active stay blocking, and a moved-in renter too', function (): void {
    $w = toDecide('confirmed', moveIn: '2027-03-15');

    app(MarkMovedIn::class)->handle($w['reservation']);

    expect(roomIsFreeFor($w['room'], '2027-03-20'))->toBeFalse();
});
