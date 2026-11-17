<?php

declare(strict_types=1);

use App\Actions\EndFinishedStay;
use App\Actions\ExpireHold;
use App\Actions\RemindAboutProof;
use App\Enums\LandlordRole;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Mail\Reservations\HoldExpired;
use App\Mail\Reservations\ProofReminder;
use App\Models\LandlordMember;
use App\Models\PaymentProof;
use App\Models\ProofReminder as ReminderRow;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
| Slice 6.3: the three scheduled commands. "Now" is 2027-03-15 10:00 Manila (02:00 UTC) unless a test moves it.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/** A reservation in `$state` on a fresh room of a fresh account. */
function jobReservation(string $state, array $attributes = []): Reservation
{
    $room = Room::factory()->for(Property::factory()->published())->create(['reservation_fee' => 150000]);

    return Reservation::factory()->{$state}()->for($room->spaces()->sole(), 'space')
        ->for(User::factory()->renter()->create(), 'renter')->create($attributes);
}

function statusOf(Reservation $reservation): ReservationStatus
{
    return Reservation::query()->findOrFail($reservation->id)->status;
}

// ------------------------------------------------------------------ the schedule

it('registers the three jobs, each without overlap', function (): void {
    $events = collect(app(Schedule::class)->events())->keyBy(fn ($event): string => (string) preg_replace('/^.*artisan[\'"]? /', '', (string) $event->command));

    expect($events->keys()->all())->toContain('reservations:expire-holds', 'reservations:end-stays', 'reservations:remind-proofs')
        ->and($events['reservations:expire-holds']->expression)->toBe('* * * * *')
        ->and($events['reservations:remind-proofs']->expression)->toBe('0 * * * *')
        // 00:05 in Manila, shown in the scheduler's own timezone.
        ->and($events['reservations:end-stays']->expression)->toBe('5 0 * * *')
        ->and((string) $events['reservations:end-stays']->timezone)->toBe('Asia/Manila');

    foreach (['reservations:expire-holds', 'reservations:end-stays', 'reservations:remind-proofs'] as $name) {
        expect($events[$name]->withoutOverlapping)->toBeTrue();
    }
});

// ------------------------------------------------------------------ reservations:expire-holds

it('expires a held reservation whose hold has run out, and emails the renter', function (): void {
    $stale = jobReservation('expiredHold');

    $this->artisan('reservations:expire-holds')->expectsOutput('reservations:expire-holds: 1 expired, 0 skipped')->assertSuccessful();

    expect(statusOf($stale))->toBe(ReservationStatus::Expired);
    Mail::assertQueued(HoldExpired::class, fn (HoldExpired $mail): bool => $mail->hasTo($stale->renter->email) && $mail->reservationId === $stale->id);
    Mail::assertQueuedCount(1);
});

it('leaves everything that is not an expired hold alone', function (): void {
    $live = jobReservation('held');
    $waiting = jobReservation('proofUploaded', ['hold_expires_at' => now()->subDay()]);
    $confirmed = jobReservation('confirmed');
    $cancelled = jobReservation('cancelled');

    $this->artisan('reservations:expire-holds')->expectsOutput('reservations:expire-holds: 0 expired, 0 skipped');

    expect(statusOf($live))->toBe(ReservationStatus::Held)
        ->and(statusOf($waiting))->toBe(ReservationStatus::ProofUploaded)
        ->and(statusOf($confirmed))->toBe(ReservationStatus::Confirmed)
        ->and(statusOf($cancelled))->toBe(ReservationStatus::Cancelled);
    Mail::assertNothingQueued();
});

it('is idempotent: a second run finds nothing and sends nothing again', function (): void {
    jobReservation('expiredHold');
    jobReservation('expiredHold');

    $this->artisan('reservations:expire-holds')->expectsOutput('reservations:expire-holds: 2 expired, 0 skipped');
    $this->artisan('reservations:expire-holds')->expectsOutput('reservations:expire-holds: 0 expired, 0 skipped');

    Mail::assertQueuedCount(2);
});

it('expires a hold exactly at hold_expires_at, not a second before', function (): void {
    $edge = jobReservation('held', ['hold_expires_at' => now()]);
    $notYet = jobReservation('held', ['hold_expires_at' => now()->addSecond()]);

    $this->artisan('reservations:expire-holds')->expectsOutput('reservations:expire-holds: 1 expired, 0 skipped');

    expect(statusOf($edge))->toBe(ReservationStatus::Expired)->and(statusOf($notYet))->toBe(ReservationStatus::Held);
});

it('skips a row that changed after it was read: a proof uploaded a second ago is not an error', function (): void {
    $stale = jobReservation('expiredHold');
    $model = Reservation::query()->findOrFail($stale->id); // still says `held`, like the command's chunk
    DB::table('reservations')->where('id', $stale->id)->update(['status' => 'proof_uploaded']);

    expect(fn () => app(ExpireHold::class)->handle($model))->toThrow(InvalidTransition::class);
    expect(statusOf($stale))->toBe(ReservationStatus::ProofUploaded);
    Mail::assertNothingQueued();
});

it('skips a hold that is live again (a rejected proof gave a new one)', function (): void {
    $renewed = jobReservation('held');

    expect(fn () => app(ExpireHold::class)->handle($renewed))->toThrow(InvalidTransition::class);
    expect(statusOf($renewed))->toBe(ReservationStatus::Held);
});

// ------------------------------------------------------------------ reservations:end-stays

it('ends active stays whose move_out has come, in Manila time, and keeps their dates', function (): void {
    $today = jobReservation('active', ['move_in' => '2026-09-15', 'move_out' => '2027-03-15']);
    $past = jobReservation('active', ['move_in' => '2026-09-01', 'move_out' => '2027-03-01']);
    $tomorrow = jobReservation('active', ['move_in' => '2026-09-16', 'move_out' => '2027-03-16']);

    $this->artisan('reservations:end-stays')->expectsOutput('reservations:end-stays: 2 ended, 0 skipped')->assertSuccessful();

    expect(statusOf($today))->toBe(ReservationStatus::Ended)
        ->and(statusOf($past))->toBe(ReservationStatus::Ended)
        ->and(statusOf($tomorrow))->toBe(ReservationStatus::Active)
        ->and(Reservation::query()->findOrFail($today->id)->move_out->toDateString())->toBe('2027-03-15');
    Mail::assertNothingQueued();
});

it('uses the Manila date: 23:30 UTC on the 14th is already the 15th there', function (): void {
    $ends = jobReservation('active', ['move_in' => '2026-09-15', 'move_out' => '2027-03-15']);

    // 22:00 Manila on Mar 14: not yet.
    $this->travelTo(CarbonImmutable::parse('2027-03-14 22:00', 'Asia/Manila'));
    $this->artisan('reservations:end-stays')->expectsOutput('reservations:end-stays: 0 ended, 0 skipped');
    expect(statusOf($ends))->toBe(ReservationStatus::Active);

    // 00:05 Manila on Mar 15 = 16:05 UTC on Mar 14, the job's time.
    $this->travelTo(CarbonImmutable::parse('2027-03-15 00:05', 'Asia/Manila'));
    expect(CarbonImmutable::now('UTC')->toDateString())->toBe('2027-03-14');
    $this->artisan('reservations:end-stays')->expectsOutput('reservations:end-stays: 1 ended, 0 skipped');
    expect(statusOf($ends))->toBe(ReservationStatus::Ended);
});

it('leaves a confirmed reservation whose stay has passed without moving in (backlog: no-shows)', function (): void {
    $noShow = jobReservation('confirmed', ['move_in' => '2026-12-01', 'move_out' => '2027-01-01']);

    $this->artisan('reservations:end-stays')->expectsOutput('reservations:end-stays: 0 ended, 0 skipped');

    expect(statusOf($noShow))->toBe(ReservationStatus::Confirmed);
});

it('is idempotent, and the action itself refuses a stay that is not over', function (): void {
    $over = jobReservation('active', ['move_in' => '2026-09-15', 'move_out' => '2027-03-15']);
    $running = jobReservation('active', ['move_in' => '2026-09-16', 'move_out' => '2027-03-16']);

    $this->artisan('reservations:end-stays')->expectsOutput('reservations:end-stays: 1 ended, 0 skipped');
    $this->artisan('reservations:end-stays')->expectsOutput('reservations:end-stays: 0 ended, 0 skipped');

    expect(fn () => app(EndFinishedStay::class)->handle($running))->toThrow(InvalidTransition::class)
        ->and(fn () => app(EndFinishedStay::class)->handle($over))->toThrow(InvalidTransition::class); // already ended
});

// ------------------------------------------------------------------ reservations:remind-proofs

/** A `proof_uploaded` reservation whose proof was uploaded `$hours` hours ago, in an account with an owner and a caretaker. */
function waitingProof(int|float $hours, string $state = 'proofUploaded'): array
{
    $reservation = jobReservation($state);

    // Even a reservation that is not waiting any more gets a pending proof, the worst case for the job.
    $proof = PaymentProof::query()->where('reservation_id', $reservation->id)->where('status', 'pending')->first()
        ?? PaymentProof::factory()->for($reservation)->create();
    $proof->update(['uploaded_at' => now()->subMinutes((int) ($hours * 60))]);

    return [
        $reservation,
        landlordUserOf($reservation->landlord_account_id),
        landlordUserOf($reservation->landlord_account_id, LandlordRole::Caretaker),
        $proof->fresh(),
    ];
}

function remindersFor(Reservation $reservation, int $hours): int
{
    return ReminderRow::query()->whereIn('payment_proof_id', PaymentProof::query()->where('reservation_id', $reservation->id)->pluck('id'))
        ->where('threshold_hours', $hours)->count();
}

it('sends nothing for a proof that has waited under 24 hours', function (): void {
    [$reservation] = waitingProof(23.5);

    $this->artisan('reservations:remind-proofs')->expectsOutput('reservations:remind-proofs: 0 reminders queued, 0 skipped');

    Mail::assertNothingQueued();
    expect(remindersFor($reservation, 24))->toBe(0);
});

it('reminds every member of the account after 24 hours, once, however often it runs', function (): void {
    [$reservation, $owner, $caretaker] = waitingProof(24.5);
    [, $strangerOwner] = waitingProof(1); // another account, nothing due

    $this->artisan('reservations:remind-proofs')->expectsOutput('reservations:remind-proofs: 1 reminders queued, 0 skipped')->assertSuccessful();
    $this->artisan('reservations:remind-proofs')->expectsOutput('reservations:remind-proofs: 0 reminders queued, 0 skipped');
    $this->artisan('reservations:remind-proofs')->expectsOutput('reservations:remind-proofs: 0 reminders queued, 0 skipped');

    Mail::assertQueued(ProofReminder::class, 2);
    Mail::assertQueued(ProofReminder::class, fn (ProofReminder $mail): bool => $mail->hasTo($owner->email) && $mail->hours === 24 && $mail->reservationId === $reservation->id);
    Mail::assertQueued(ProofReminder::class, fn (ProofReminder $mail): bool => $mail->hasTo($caretaker->email));
    Mail::assertNotQueued(ProofReminder::class, fn (ProofReminder $mail): bool => $mail->hasTo($strangerOwner->email));
    expect(remindersFor($reservation, 24))->toBe(1);
});

it('sends the second reminder after 48 hours, once, and the first one is not repeated', function (): void {
    [$reservation] = waitingProof(25);
    $this->artisan('reservations:remind-proofs');
    Mail::assertQueued(ProofReminder::class, 2);

    $this->travelTo(now()->addHours(24)); // the proof is now 49 hours old
    $this->artisan('reservations:remind-proofs')->expectsOutput('reservations:remind-proofs: 1 reminders queued, 0 skipped');
    $this->artisan('reservations:remind-proofs')->expectsOutput('reservations:remind-proofs: 0 reminders queued, 0 skipped');

    Mail::assertQueued(ProofReminder::class, 4);
    Mail::assertQueued(ProofReminder::class, fn (ProofReminder $mail): bool => $mail->hours === 48);
    expect(remindersFor($reservation, 24))->toBe(1)->and(remindersFor($reservation, 48))->toBe(1);
});

it('sends only the 48-hour reminder for a proof that is already past both (a job that was down)', function (): void {
    [$reservation] = waitingProof(60);

    $this->artisan('reservations:remind-proofs')->expectsOutput('reservations:remind-proofs: 1 reminders queued, 0 skipped');

    Mail::assertQueued(ProofReminder::class, 2);
    Mail::assertNotQueued(ProofReminder::class, fn (ProofReminder $mail): bool => $mail->hours === 24);
    expect(remindersFor($reservation, 48))->toBe(1);
});

it('reminds at exactly 24 hours (the threshold is inclusive, like the dashboard figure)', function (): void {
    waitingProof(24);

    $this->artisan('reservations:remind-proofs')->expectsOutput('reservations:remind-proofs: 1 reminders queued, 0 skipped');
});

it('does not remind about a reservation that is no longer waiting', function (string $state): void {
    [$reservation] = waitingProof(30, $state);

    $this->artisan('reservations:remind-proofs')->expectsOutput('reservations:remind-proofs: 0 reminders queued, 0 skipped');

    Mail::assertNothingQueued();
})->with(['confirmed', 'cancelled', 'declined']);

it('does not remind about a proof that was already answered', function (): void {
    [, , , $proof] = waitingProof(30);
    $proof->update(['status' => 'rejected']);

    $this->artisan('reservations:remind-proofs')->expectsOutput('reservations:remind-proofs: 0 reminders queued, 0 skipped');
});

it('starts again for a new proof after a rejection', function (): void {
    [$reservation, , , $old] = waitingProof(30);
    $this->artisan('reservations:remind-proofs');
    Mail::assertQueued(ProofReminder::class, 2);

    // The landlord sent it back and the renter uploaded another one an hour ago.
    $old->update(['status' => 'rejected']);
    PaymentProof::factory()->for($reservation)->create(['uploaded_at' => now()->subHour()]);
    $this->artisan('reservations:remind-proofs')->expectsOutput('reservations:remind-proofs: 0 reminders queued, 0 skipped');

    $this->travelTo(now()->addDay());
    $this->artisan('reservations:remind-proofs')->expectsOutput('reservations:remind-proofs: 1 reminders queued, 0 skipped');
    Mail::assertQueued(ProofReminder::class, 4);
});

it('records a reminder at most once per proof and threshold, whatever the code does (the unique key)', function (): void {
    [, , , $proof] = waitingProof(30);
    ReminderRow::query()->create(['payment_proof_id' => $proof->id, 'threshold_hours' => 48, 'sent_at' => now()]);

    expect(fn () => ReminderRow::query()->create(['payment_proof_id' => $proof->id, 'threshold_hours' => 48, 'sent_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('does not queue a second reminder when another run claimed it first', function (): void {
    [, , , $proof] = waitingProof(25);

    // Two overlapping runs read the same list; the first one wins the unique key.
    expect(app(RemindAboutProof::class)->handle($proof, 24))->toBeTrue()
        ->and(app(RemindAboutProof::class)->handle($proof, 24))->toBeFalse();

    Mail::assertQueued(ProofReminder::class, 2);
    expect(LandlordMember::query()->where('landlord_account_id', $proof->landlord_account_id)->count())->toBe(2);
});
