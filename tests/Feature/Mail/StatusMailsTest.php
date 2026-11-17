<?php

declare(strict_types=1);

use App\Actions\AcceptViewing;
use App\Actions\CancelReservation;
use App\Actions\CancelViewing;
use App\Actions\ConfirmReservation;
use App\Actions\DeclineReservation;
use App\Actions\DeclineViewing;
use App\Actions\MarkViewingDone;
use App\Actions\RejectProof;
use App\Actions\RequestViewing;
use App\Actions\UndoConfirmation;
use App\Actions\UploadPaymentProof;
use App\Actions\WithdrawReservation;
use App\Enums\LandlordRole;
use App\Enums\ProofFileType;
use App\Exceptions\Domain\InvalidTransition;
use App\Exceptions\Domain\NoSpaceAvailable;
use App\Mail\NoticeMail;
use App\Mail\Reservations\ConfirmationUndone;
use App\Mail\Reservations\HoldCreated;
use App\Mail\Reservations\ProofRejected;
use App\Mail\Reservations\ProofUploaded;
use App\Mail\Reservations\ReservationCancelled;
use App\Mail\Reservations\ReservationConfirmed;
use App\Mail\Reservations\ReservationDeclined;
use App\Mail\Reservations\ReservationWithdrawn;
use App\Mail\Viewings\ViewingAnswered;
use App\Mail\Viewings\ViewingCancelled;
use App\Mail\Viewings\ViewingRequested;
use App\Models\LandlordAccount;
use App\Models\LandlordMember;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Models\ViewingRequest;
use App\Support\Mail\MailCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;

/*
| Slice 6.3: every status email (docs/RESERVATIONS.md side-effects column, docs/ACCESS.md flow C). "Now" is
| 2027-03-15 10:00 Manila. TestCase fakes the mailer, so these assert exactly which mails each move queues,
| to whom, and then render each one against real rows.
*/

beforeEach(function (): void {
    fakeMediaDisks();
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/**
 * @return array{reservation: Reservation, owner: User, caretaker: User, room: Room, renter: User, outsider: User}
 */
function mailWorld(string $state): array
{
    $world = toDecide($state, ['hold_expires_at' => now()->addDay()]);
    $world['renter'] = $world['reservation']->renter;
    // Another account's owner and another renter: neither may ever get or see any of it.
    $world['outsider'] = landlordUser();

    return $world;
}

function queuedTo(string $class, User ...$users): void
{
    Mail::assertQueued($class, count($users));

    foreach ($users as $user) {
        Mail::assertQueued($class, fn ($mail): bool => $mail->hasTo($user->email));
    }
}

// ------------------------------------------------------------------ who gets what

it('ReserveSpace emails the renter the hold, and only on success', function (): void {
    $renter = User::factory()->renter()->create();
    $room = Room::factory()->for(Property::factory()->published())->create(['reservation_fee' => 150000]);

    $reservation = reserve($renter, $room, '2027-04-01');

    queuedTo(HoldCreated::class, $renter);
    Mail::assertQueued(HoldCreated::class, fn (HoldCreated $mail): bool => $mail->reservationId === $reservation->id);
    Mail::assertQueuedCount(1);

    // The only space is taken: the refused attempt sends nothing.
    expect(fn () => reserve(User::factory()->renter()->create(), $room, '2027-04-01'))->toThrow(NoSpaceAvailable::class);
    Mail::assertQueuedCount(1);
});

it('a proof upload emails every member of the account, with the amount and reference', function (): void {
    $world = mailWorld('held');

    app(UploadPaymentProof::class)->handle($world['renter'], $world['reservation'], UploadedFile::fake()->createWithContent('r.png', photoBytes(400, 700, 'png')), ProofFileType::Png, 150000, 'GC-77');

    queuedTo(ProofUploaded::class, $world['owner'], $world['caretaker']);
    Mail::assertNotQueued(ProofUploaded::class, fn ($mail): bool => $mail->hasTo($world['outsider']->email) || $mail->hasTo($world['renter']->email));
    Mail::assertQueued(ProofUploaded::class, fn (ProofUploaded $mail): bool => $mail->amount === '₱1,500' && $mail->referenceNo === 'GC-77');
});

it('the landlord moves email the renter, and only the renter', function (string $state, Closure $move, string $mail): void {
    $world = mailWorld($state);

    $move($world);

    queuedTo($mail, $world['renter']);
    Mail::assertQueuedCount(1);
})->with([
    'proof rejected' => ['proofUploaded', fn (array $w) => app(RejectProof::class)->handle($w['owner'], $w['reservation'], 'Blurry.'), ProofRejected::class],
    'confirmed' => ['proofUploaded', fn (array $w) => app(ConfirmReservation::class)->handle($w['caretaker'], $w['reservation']), ReservationConfirmed::class],
    'declined' => ['proofUploaded', fn (array $w) => app(DeclineReservation::class)->handle($w['owner'], $w['reservation'], 'No.'), ReservationDeclined::class],
    'confirmation undone' => ['confirmed', fn (array $w) => app(UndoConfirmation::class)->handle($w['reservation']), ConfirmationUndone::class],
    'withdrawn' => ['confirmed', fn (array $w) => app(WithdrawReservation::class)->handle($w['owner'], $w['reservation'], 'Closed.'), ReservationWithdrawn::class],
]);

it('carries the landlord\'s reason in the mail', function (): void {
    $world = mailWorld('proofUploaded');

    app(RejectProof::class)->handle($world['owner'], $world['reservation'], 'The amount is wrong.');

    Mail::assertQueued(ProofRejected::class, fn (ProofRejected $mail): bool => $mail->reason === 'The amount is wrong.');
});

it('a renter cancelling emails the landlord members when a proof had been sent, and nobody when it was only a hold', function (): void {
    $withProof = mailWorld('proofUploaded');
    app(CancelReservation::class)->handle($withProof['renter'], $withProof['reservation']);
    queuedTo(ReservationCancelled::class, $withProof['owner'], $withProof['caretaker']);

    // (A confirmed reservation's account also has the owner who confirmed it: count the members, not the people the test made.)
    $confirmed = mailWorld('confirmed');
    app(CancelReservation::class)->handle($confirmed['renter'], $confirmed['reservation']);
    $members = LandlordMember::query()->where('landlord_account_id', $confirmed['reservation']->landlord_account_id)->count();
    expect($members)->toBeGreaterThanOrEqual(2);
    Mail::assertQueued(ReservationCancelled::class, 2 + $members);

    $held = mailWorld('held');
    app(CancelReservation::class)->handle($held['renter'], $held['reservation']);
    Mail::assertQueued(ReservationCancelled::class, 2 + $members);
    Mail::assertNotQueued(ReservationCancelled::class, fn ($mail): bool => $mail->hasTo($held['owner']->email));
});

it('a move the table refuses queues nothing', function (): void {
    $world = mailWorld('confirmed');

    expect(fn () => app(ConfirmReservation::class)->handle($world['owner'], $world['reservation']))->toThrow(InvalidTransition::class)
        ->and(fn () => app(DeclineReservation::class)->handle($world['owner'], $world['reservation'], 'x'))->toThrow(InvalidTransition::class);

    Mail::assertNothingQueued();
});

// ------------------------------------------------------------------ viewings

/**
 * @return array{viewing: ViewingRequest, owner: User, caretaker: User, renter: User}
 */
function viewingWorld(string $state = 'requested'): array
{
    $owner = landlordUser();
    $property = Property::factory()->published()->at(placeIn('manila'))->create(['landlord_account_id' => accountIdOf($owner)]);
    $renter = User::factory()->renter()->create();

    return [
        'viewing' => ViewingRequest::factory()->for($property)->for($renter, 'renter')->at(CarbonImmutable::parse('2027-03-17 09:00', 'Asia/Manila'))
            ->state(['message' => 'Can I bring my mother?'])->{$state}()->create(),
        'owner' => $owner,
        'caretaker' => landlordUserOf(accountIdOf($owner), LandlordRole::Caretaker),
        'renter' => $renter,
    ];
}

it('a viewing request emails the landlord members', function (): void {
    ['viewing' => $viewing, 'owner' => $owner, 'caretaker' => $caretaker, 'renter' => $renter] = viewingWorld();
    $other = User::factory()->renter()->create();

    $created = app(RequestViewing::class)->handle($other, $viewing->property, CarbonImmutable::parse('2027-03-18 10:00', 'Asia/Manila'), 'Hello');

    queuedTo(ViewingRequested::class, $owner, $caretaker);
    Mail::assertQueued(ViewingRequested::class, fn (ViewingRequested $mail): bool => $mail->viewingId === $created->id);
    Mail::assertNotQueued(ViewingRequested::class, fn ($mail): bool => $mail->hasTo($renter->email) || $mail->hasTo($other->email));
});

it('accepting or declining a viewing emails the renter', function (): void {
    $accepted = viewingWorld();
    app(AcceptViewing::class)->handle($accepted['owner'], $accepted['viewing'], 'Gate 2.');
    queuedTo(ViewingAnswered::class, $accepted['renter']);
    Mail::assertQueued(ViewingAnswered::class, fn (ViewingAnswered $mail): bool => $mail->accepted);

    $declined = viewingWorld();
    app(DeclineViewing::class)->handle($declined['caretaker'], $declined['viewing'], 'Not that day.');
    Mail::assertQueued(ViewingAnswered::class, 2);
    Mail::assertQueued(ViewingAnswered::class, fn (ViewingAnswered $mail): bool => ! $mail->accepted && $mail->hasTo($declined['renter']->email));
});

it('a renter cancelling a viewing emails the landlord members; marking one done emails nobody', function (): void {
    $world = viewingWorld('accepted');
    app(CancelViewing::class)->handle($world['renter'], $world['viewing']);
    queuedTo(ViewingCancelled::class, $world['owner'], $world['caretaker']);
    Mail::assertQueuedCount(2);

    $this->travelTo(CarbonImmutable::parse('2027-03-17 09:30', 'Asia/Manila'));
    $done = viewingWorld('accepted');
    $done['viewing']->update(['preferred_at' => CarbonImmutable::parse('2027-03-17 09:00', 'Asia/Manila')->utc()]);
    app(MarkViewingDone::class)->handle($done['owner'], $done['viewing']);
    Mail::assertQueuedCount(2);
});

// ------------------------------------------------------------------ after commit

it('flags every mailable to be queued after commit', function (): void {
    $world = mailWorld('proofUploaded');
    app(ConfirmReservation::class)->handle($world['owner'], $world['reservation']);

    Mail::assertQueued(ReservationConfirmed::class, fn (ReservationConfirmed $mail): bool => $mail->afterCommit === true);
});

it('pushes the job only when the surrounding transaction commits, and never when it rolls back', function (): void {
    // The real mailer, on the database queue (TestCase fakes it for every other test).
    $this->app->forgetInstance('mail.manager');
    Facade::clearResolvedInstance('mail.manager');
    expect(Mail::getFacadeRoot())->toBeInstanceOf(MailManager::class);
    config(['queue.default' => 'database']);

    $world = mailWorld('proofUploaded');

    DB::transaction(function () use ($world): void {
        app(ConfirmReservation::class)->handle($world['owner'], $world['reservation']);
        expect(DB::table('jobs')->count())->toBe(0); // still inside the caller's transaction: not queued yet
    });
    expect(DB::table('jobs')->count())->toBe(1);

    $other = mailWorld('proofUploaded');
    try {
        DB::transaction(function () use ($other): void {
            app(ConfirmReservation::class)->handle($other['owner'], $other['reservation']);

            throw new RuntimeException('something later in the request failed');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('jobs')->count())->toBe(1)
        ->and($other['reservation']->fresh()->status->value)->toBe('proof_uploaded');
});

// ------------------------------------------------------------------ what each mail says

/** A complete world: one reservation of an account and a viewing of its property, plus a stranger with a phone. */
function renderWorld(): array
{
    $world = mailWorld('proofUploaded');
    $property = $world['room']->property;
    $property->update(['payment_instructions' => "GCash 0917 000 1111\nAccount: Maria Santos"]);
    $world['renter']->update(['phone' => '09175550101']);
    $world['owner']->update(['phone' => '09175550202']);
    $viewing = ViewingRequest::factory()->for($property)->for($world['renter'], 'renter')
        ->at(CarbonImmutable::parse('2027-03-17 15:30', 'Asia/Manila'))->state(['message' => 'Can I bring my mother?', 'landlord_note' => 'Gate 2.'])->accepted()->create();
    $world['viewing'] = $viewing;
    $world['stranger'] = User::factory()->renter()->create(['phone' => '09175559999']);

    return $world;
}

it('renders every mail with the facts, one button, and the Manila time labelled', function (): void {
    $world = renderWorld();
    $property = $world['room']->property;
    $catalog = MailCatalog::all($world['reservation']->id, $world['viewing']->id);

    expect($catalog)->toHaveCount(15);

    foreach ($catalog as $key => ['mail' => $mail]) {
        $html = $mail->render();
        $subject = (string) $mail->envelope()->subject;

        expect($subject, $key)->not->toBeEmpty()->and($html, $key)->toContain($property->name)
            ->and(substr_count($html, 'class="button button-'), "$key: exactly one button")->toBe(1)
            ->and($html, $key)->toContain('copy this link into your browser');
    }
});

it('the hold mail has the payment instructions, the deadline and the fee', function (): void {
    $world = renderWorld();
    $mail = new HoldCreated($world['reservation']->id);
    $html = $mail->render();

    expect((string) $mail->envelope()->subject)->toBe("Your hold at {$world['room']->property->name} is ready")
        ->and($html)->toContain('GCash 0917 000 1111')->toContain('Account: Maria Santos')
        ->and($html)->toContain('Tue, 16 Mar 2027, 10:00 AM Philippine time')
        ->and($html)->toContain('₱1,500')
        ->and($html)->toContain(route('reservations.show', $world['reservation']->id));
});

it('the proof mail goes to the landlord page and names the renter, amount and reference', function (): void {
    $world = renderWorld();
    $html = (new ProofUploaded($world['reservation']->id, '₱1,500', 'GC-77'))->render();

    expect($html)->toContain($world['renter']->full_name)->toContain('₱1,500')->toContain('GC-77')
        ->toContain(route('landlord.reservations.show', $world['reservation']->id));
});

it('the confirmed mail gives the move-in date and the landlord phone', function (): void {
    $world = renderWorld();
    $html = (new ReservationConfirmed($world['reservation']->id))->render();

    expect($html)->toContain('Thu, 1 Apr 2027')->toContain(LandlordAccount::query()->findOrFail($world['reservation']->landlord_account_id)->contact_phone);
});

it('a reason or note is escaped and never read as Markdown or HTML', function (): void {
    $world = renderWorld();
    $html = (new ReservationDeclined($world['reservation']->id, "Call me [here](http://evil.example)\n<script>alert(1)</script> **now**"))->render();

    expect($html)->not->toContain('href="http://evil.example"')->not->toContain('<script>alert')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')->toContain('[here](http://evil.example)')->toContain('**now**');
});

it('the viewing mails: an accepted one carries the address and phone, a declined one does not', function (): void {
    $world = renderWorld();
    $address = $world['room']->property->full_address;
    $phone = LandlordAccount::query()->findOrFail($world['reservation']->landlord_account_id)->contact_phone;

    $accepted = (new ViewingAnswered($world['viewing']->id, accepted: true))->render();
    $declined = (new ViewingAnswered($world['viewing']->id, accepted: false))->render();

    expect($accepted)->toContain($address)->toContain($phone)->toContain('Gate 2.')->toContain('Wed, 17 Mar 2027, 3:30 PM Philippine time')
        ->and($declined)->not->toContain($address)->not->toContain($phone);
});

it('never puts another person\'s contact details in a mail', function (): void {
    $world = renderWorld();
    $catalog = MailCatalog::all($world['reservation']->id, $world['viewing']->id);
    $secretsOfOthers = [$world['stranger']->email, $world['stranger']->phone, $world['outsider']->email];

    foreach ($catalog as $key => ['mail' => $mail, 'to' => $to]) {
        $html = $mail->render();

        foreach ($secretsOfOthers as $secret) {
            expect($html, "$key leaks $secret")->not->toContain((string) $secret);
        }

        // The renter's phone and email go nowhere, not even to the landlord's own mails (they are behind the login).
        if ($to !== 'The renter') {
            expect($html, "$key leaks the renter's phone")->not->toContain('09175550101')->not->toContain($world['renter']->email);
        }

        // A renter's mail never carries a landlord member's email or private phone.
        if ($to === 'The renter') {
            expect($html, "$key leaks a member")->not->toContain($world['owner']->email)->not->toContain($world['caretaker']->email)->not->toContain('09175550202');
        }
    }
});

it('the catalog has every mailable class', function (): void {
    $world = renderWorld();
    $inCatalog = collect(MailCatalog::all($world['reservation']->id, $world['viewing']->id))->map(fn (array $row): string => $row['mail']::class)->unique()->sort()->values()->all();

    $onDisk = collect([...glob(app_path('Mail/Reservations/*.php')), ...glob(app_path('Mail/Viewings/*.php'))])
        ->map(fn (string $file): string => 'App\\Mail\\'.basename(dirname($file)).'\\'.basename($file, '.php'))->sort()->values()->all();

    expect($inCatalog)->toBe($onDisk)->and(is_subclass_of($onDisk[0], NoticeMail::class))->toBeTrue();
});

it('shows every mail at /dev/mail with demo data, and 404s in production', function (): void {
    $world = renderWorld();

    $this->get('/dev/mail')->assertOk()->assertSee('Hold created')->assertSee('Viewing cancelled');
    $this->get('/dev/mail/hold-created')->assertOk()->assertSee($world['room']->property->name);
    $this->get('/dev/mail/nope')->assertNotFound();

    $this->app->detectEnvironment(fn (): string => 'production');
    $this->get('/dev/mail')->assertNotFound();
    $this->get('/dev/mail/hold-created')->assertNotFound();
});
