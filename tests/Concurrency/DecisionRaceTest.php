<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Enums\PaymentProofStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\Exchange;
use Tests\Concurrency\Support\Race;
use Tests\Concurrency\Support\Racer;

/*
| Slice 6.1, through real, parallel HTTP requests (see ReservationRaceTest.php for the harness).
|
| 1. The owner confirms while the caretaker declines the same reservation. Both actions lock the reservation row
|    FOR UPDATE and re-read its status, so they run one after the other: exactly ONE wins, the other gets the
|    transition table's 422, and the audit log has exactly ONE row for the reservation.
|
| 2. The landlord confirms while the renter cancels. The move-in date is today, so a confirmed stay can no longer be
|    cancelled (RESERVATIONS.md): whichever commits first wins, the other gets a 422, and the reservation, its proof
|    and the audit log all tell the same story.
*/

const DECISION_ROUNDS = 6;

/**
 * Left alone, one side tends to win every round (it reaches the lock a few milliseconds sooner), so each round
 * gives one side a small head start, alternating and growing: round 1 delays the second request 5 ms, round 2 the
 * first 5 ms, round 3 the second 10 ms… Both orders get sampled; the server's clock must still show overlap.
 *
 * @param  array{Exchange, Exchange}  $pair
 */
function staggered(array $pair, int $round): void
{
    $pair[$round % 2 === 1 ? 1 : 0]->after(intdiv($round + 1, 2) * 0.005);
}

/**
 * A `proof_uploaded` reservation (with its pending proof) in a fresh account, moving in on `$moveIn`,
 * plus an owner and a caretaker of the account.
 *
 * @return array{reservation: Reservation, owner: User, caretaker: User}
 */
function waitingReservation(string $moveIn): array
{
    $room = Room::factory()->for(Property::factory()->published())->create();
    $reservation = Reservation::factory()->proofUploaded()->for($room->spaces()->sole(), 'space')->for(User::factory()->renter()->create(), 'renter')
        ->create(['move_in' => $moveIn, 'move_out' => CarbonImmutable::parse($moveIn)->addMonths(6)->toDateString()]);

    return [
        'reservation' => $reservation,
        'owner' => landlordUserOf($room->landlord_account_id),
        'caretaker' => landlordUserOf($room->landlord_account_id, LandlordRole::Caretaker),
    ];
}

/** @return list<string> the audit actions written for the reservation */
function auditActionsFor(Reservation $reservation): array
{
    return DB::table('audit_logs')->where('subject_type', 'reservation')->where('subject_id', $reservation->id)->orderBy('id')->pluck('action')->all();
}

/** @return Closure(Exchange): string */
function decisionOutcome(): Closure
{
    return fn (Exchange $e): string => $e->status === 200 ? 'won: '.($e->json()['status'] ?? '?') : ($e->message() ?: 'HTTP '.$e->status);
}

it('confirm versus decline: the owner confirms while the caretaker declines, and exactly one wins', function (): void {
    $round = nextRound('ConfirmDecline');
    $w = waitingReservation(BusinessDate::today()->addDays(14)->toDateString());
    $owner = new Racer($w['owner'], 'owner');
    $caretaker = new Racer($w['caretaker'], 'caretaker');
    Racer::logInAll($this->http, [$owner, $caretaker]);

    $id = $w['reservation']->id;
    $confirm = $owner->postJson("/landlord/reservations/{$id}/confirm", []);
    $decline = $caretaker->postJson("/landlord/reservations/{$id}/decline", ['reason' => 'Not a fit for the house rules.']);
    staggered([$confirm, $decline], $round);

    Race::run(
        $this->http,
        sprintf('Confirm versus decline round %d/%d: owner confirms, caretaker declines', $round, DECISION_ROUNDS),
        [$confirm, $decline],
        decisionOutcome(),
    );

    $fresh = $w['reservation']->fresh();
    $proof = PaymentProof::query()->where('reservation_id', $id)->sole();
    $audit = auditActionsFor($w['reservation']);
    [$winner, $loser] = $fresh->status === ReservationStatus::Confirmed ? [$confirm, $decline] : [$decline, $confirm];

    expect([$confirm->status, $decline->status])->toContain(200)
        ->and($winner->status)->toBe(200, 'BOTH WON or NEITHER: '.$confirm->describe().' / '.$decline->describe())
        ->and($loser->status)->toBe(422, ($loser->status === 200 ? 'BOTH WON: ' : 'Unexpected response: ').$confirm->describe().' / '.$decline->describe())
        ->and($audit)->toHaveCount(1, 'AUDIT: '.count($audit).' rows for one decision: '.implode(', ', $audit));

    if ($fresh->status === ReservationStatus::Confirmed) {
        expect($loser->message())->toBe(InvalidTransition::between(ReservationStatus::Confirmed, ReservationStatus::Declined)->getMessage())
            ->and($proof->status)->toBe(PaymentProofStatus::Accepted)
            ->and($fresh->decided_by)->toBe($w['owner']->id)
            ->and($audit)->toBe(['reservation.confirmed']);
    } else {
        expect($fresh->status)->toBe(ReservationStatus::Declined)
            ->and($loser->message())->toBe(InvalidTransition::between(ReservationStatus::Declined, ReservationStatus::Confirmed)->getMessage())
            ->and($proof->status)->toBe(PaymentProofStatus::Rejected)
            ->and($fresh->decided_by)->toBe($w['caretaker']->id)
            ->and($audit)->toBe(['reservation.declined']);
    }
})->repeat(DECISION_ROUNDS);

it('confirm versus cancel: the landlord confirms while the renter cancels on the move-in date, and exactly one outcome holds', function (): void {
    $round = nextRound('ConfirmCancel');
    $w = waitingReservation(BusinessDate::today()->toDateString());
    $owner = new Racer($w['owner'], 'owner');
    $renter = new Racer($w['reservation']->renter, 'renter');
    Racer::logInAll($this->http, [$owner, $renter]);

    $id = $w['reservation']->id;
    $confirm = $owner->postJson("/landlord/reservations/{$id}/confirm", []);
    $cancel = $renter->postJson("/reservations/{$id}/cancel", []);
    staggered([$confirm, $cancel], $round);

    Race::run(
        $this->http,
        sprintf('Confirm versus cancel round %d/%d: owner confirms, renter cancels', $round, DECISION_ROUNDS),
        [$confirm, $cancel],
        fn (Exchange $e): string => $e->status === 200 || $e->status === 302 ? 'won' : ($e->message() ?: 'HTTP '.$e->status),
    );

    $fresh = $w['reservation']->fresh();
    $proof = PaymentProof::query()->where('reservation_id', $id)->sole();
    $audit = auditActionsFor($w['reservation']);

    if ($fresh->status === ReservationStatus::Confirmed) {
        // The confirm committed first: the stay has started, so the renter's cancel is refused.
        expect($confirm->status)->toBe(200, 'Unexpected response: '.$confirm->describe())
            ->and($cancel->status)->toBe(422, 'BOTH WON: '.$cancel->describe())
            ->and($cancel->message())->toBe(InvalidTransition::stayStarted()->getMessage())
            ->and($proof->status)->toBe(PaymentProofStatus::Accepted)
            ->and($fresh->cancelled_at)->toBeNull()
            ->and($audit)->toBe(['reservation.confirmed']);
    } else {
        expect($fresh->status)->toBe(ReservationStatus::Cancelled, "INCONSISTENT: the reservation is {$fresh->status->value}")
            ->and($cancel->status)->toBeIn([200, 302], 'Unexpected response: '.$cancel->describe())
            ->and($confirm->status)->toBe(422, 'BOTH WON: '.$confirm->describe())
            ->and($confirm->message())->toBe(InvalidTransition::between(ReservationStatus::Cancelled, ReservationStatus::Confirmed)->getMessage())
            ->and($proof->status)->toBe(PaymentProofStatus::Pending)
            ->and($fresh->cancelled_by)->toBe($w['reservation']->renter_id)
            ->and($fresh->decided_by)->toBeNull()
            ->and($audit)->toBe([]);
    }
})->repeat(DECISION_ROUNDS);
