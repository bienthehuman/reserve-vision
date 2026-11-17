<?php

declare(strict_types=1);

use App\Actions\RequestViewing;
use App\Enums\ViewingRequestStatus;
use App\Exceptions\Domain\CannotRequestViewing;
use App\Exceptions\Domain\NotARenter;
use App\Models\Property;
use App\Models\User;
use App\Models\ViewingRequest;
use App\Support\Viewings\ViewingSlots;
use Carbon\CarbonImmutable;

/*
| Asking for a viewing (slice 6.2, docs/ACCESS.md flow C). The clock is Manila's: tests freeze "now" in Manila time.
| 2027-03-15 is a Monday.
*/

beforeEach(function (): void {
    fakeMediaDisks();
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/** A Manila moment, "2027-03-15 12:00". */
function manila(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse($moment, 'Asia/Manila');
}

function viewingBody(Property $property, string $date = '2027-03-16', string $time = '09:00', array $extra = []): array
{
    return ['property_id' => $property->id, 'date' => $date, 'time' => $time, ...$extra];
}

it('stores preferred_at in UTC for a Manila time, and a trimmed message', function (): void {
    [$property] = listedProperty();
    $renter = User::factory()->renter()->create();

    $viewing = app(RequestViewing::class)->handle($renter, $property, manila('2027-03-16 15:30'), "  I'll come with my sister.  ");

    expect($viewing->status)->toBe(ViewingRequestStatus::Requested)
        ->and($viewing->message)->toBe("I'll come with my sister.")
        ->and($viewing->landlord_account_id)->toBe($property->landlord_account_id)
        ->and($viewing->decided_by)->toBeNull()
        ->and(DB::table('viewing_requests')->where('id', $viewing->id)->value('preferred_at'))->toBe('2027-03-16 07:30:00')
        ->and(auditRows())->toBeEmpty();
});

it('earliest() is the first 30-minute step at least 2 hours away', function (string $now, string $earliest): void {
    $this->travelTo(manila($now));

    expect(ViewingSlots::earliest()->format('Y-m-d H:i'))->toBe($earliest);
})->with([
    'on a step: 10:00 + 2h' => ['2027-03-15 10:00:00', '2027-03-15 12:00'],
    'a second past: rounds up' => ['2027-03-15 10:00:01', '2027-03-15 12:30'],
    'a minute past' => ['2027-03-15 10:01', '2027-03-15 12:30'],
    'on the half hour' => ['2027-03-15 10:30', '2027-03-15 12:30'],
    '29 minutes past' => ['2027-03-15 10:29', '2027-03-15 12:30'],
    'last slot today: 16:00 + 2h = 18:00' => ['2027-03-15 16:00:00', '2027-03-15 18:00'],
    'a second after 16:00: none left today' => ['2027-03-15 16:00:01', '2027-03-16 08:00'],
    'evening' => ['2027-03-15 21:15', '2027-03-16 08:00'],
    'before the opening: 05:00 + 2h = 07:00' => ['2027-03-15 05:00', '2027-03-15 08:00'],
    'early morning rounds up to the opening' => ['2027-03-15 06:00', '2027-03-15 08:00'],
    'the first slot to count: 06:00:01' => ['2027-03-15 06:00:01', '2027-03-15 08:30'],
    'across midnight, 23:30 Manila is already tomorrow for the UTC clock' => ['2027-03-15 23:30', '2027-03-16 08:00'],
]);

it('allows a same-day viewing exactly 2 hours away and refuses one a step sooner', function (): void {
    [$property] = listedProperty();
    $renter = User::factory()->renter()->create();
    $ask = fn (string $moment) => app(RequestViewing::class)->handle($renter, $property, manila($moment), null);

    expect(fn () => $ask('2027-03-15 11:30'))->toThrow(CannotRequestViewing::class, 'The earliest time you can ask for is Mon 15 Mar, 12:00 PM')
        ->and($ask('2027-03-15 12:00')->preferred_at->format('H:i'))->toBe('04:00');
});

it('gives no slot after 4 PM today: the last one is tomorrow at 8 AM', function (): void {
    [$property] = listedProperty();
    $renter = User::factory()->renter()->create();
    $this->travelTo(manila('2027-03-15 16:01'));

    expect(fn () => app(RequestViewing::class)->handle($renter, $property, manila('2027-03-15 18:00'), null))
        ->toThrow(CannotRequestViewing::class, 'Tue 16 Mar, 8:00 AM')
        ->and(app(RequestViewing::class)->handle($renter, $property, manila('2027-03-16 08:00'), null)->status)->toBe(ViewingRequestStatus::Requested);
});

it('at exactly 4 PM, 6 PM is still open', function (): void {
    [$property] = listedProperty();
    $renter = User::factory()->renter()->create();
    $this->travelTo(manila('2027-03-15 16:00:00'));

    expect(app(RequestViewing::class)->handle($renter, $property, manila('2027-03-15 18:00'), null)->preferred_at->format('H:i'))->toBe('10:00');
});

it('refuses times off the grid: before 8, after 6 PM, not on a half hour, with seconds', function (string $moment): void {
    [$property] = listedProperty();
    $renter = User::factory()->renter()->create();

    expect(fn () => app(RequestViewing::class)->handle($renter, $property, manila($moment), null))
        ->toThrow(CannotRequestViewing::class, 'between 8:00 AM and 6:00 PM');
})->with([
    'before opening' => '2027-03-16 07:30',
    'after closing' => '2027-03-16 18:30',
    'at night' => '2027-03-16 22:00',
    'a quarter past' => '2027-03-16 09:15',
    'a minute past' => '2027-03-16 09:01',
    'with seconds' => '2027-03-16 09:00:30',
]);

it('accepts every one of the 21 slots', function (): void {
    expect(ViewingSlots::times())->toHaveCount(21)->and(ViewingSlots::times()[0])->toBe('08:00')->and(ViewingSlots::times()[20])->toBe('18:00');

    foreach (ViewingSlots::times() as $time) {
        expect(ViewingSlots::isSlot(ViewingSlots::at('2027-03-16', $time) ?? throw new LogicException))->toBeTrue($time);
    }
});

it('allows up to 30 days ahead (Manila dates) and no further', function (): void {
    [$property] = listedProperty();
    $renter = User::factory()->renter()->create();

    expect(app(RequestViewing::class)->handle($renter, $property, manila('2027-04-14 18:00'), null)->exists)->toBeTrue();

    $other = Property::factory()->published()->at(placeIn('manila'))->create();

    expect(fn () => app(RequestViewing::class)->handle($renter, $other, manila('2027-04-15 08:00'), null))
        ->toThrow(CannotRequestViewing::class, 'up to 30 days ahead');
});

it('refuses a time in the past', function (): void {
    [$property] = listedProperty();
    $renter = User::factory()->renter()->create();

    expect(fn () => app(RequestViewing::class)->handle($renter, $property, manila('2027-03-14 09:00'), null))->toThrow(CannotRequestViewing::class, 'at least 2 hours');
});

it('limits a renter to 3 open requests, and 1 per property', function (): void {
    $renter = User::factory()->renter()->create();
    $properties = Property::factory()->published()->at(placeIn('manila'))->count(4)->create();
    $ask = fn (Property $property, string $moment = '2027-03-17 09:00') => app(RequestViewing::class)->handle($renter, $property, manila($moment), null);

    $ask($properties[0]);
    expect(fn () => $ask($properties[0], '2027-03-18 09:00'))->toThrow(CannotRequestViewing::class, 'open at this property');

    $ask($properties[1]);
    $ask($properties[2]);
    expect(fn () => $ask($properties[3]))->toThrow(CannotRequestViewing::class, '3 viewing requests open');
    expect(ViewingRequest::query()->where('renter_id', $renter->id)->count())->toBe(3);
});

it('counts only open requests: declined, cancelled, done and past ones free the limit', function (): void {
    $renter = User::factory()->renter()->create();
    $properties = Property::factory()->published()->at(placeIn('manila'))->count(5)->create();
    $make = fn (Property $property, string $state, string $moment) => ViewingRequest::factory()->for($property)->for($renter, 'renter')->{$state}()->at(manila($moment))->create();

    $make($properties[0], 'declined', '2027-03-17 09:00');
    $make($properties[1], 'cancelled', '2027-03-17 09:00');
    $make($properties[2], 'done', '2027-03-14 09:00');
    // Time has come and gone with nobody answering: not open either (requested, preferred_at before now).
    ViewingRequest::factory()->for($properties[3])->for($renter, 'renter')->at(manila('2027-03-15 09:00'))->create();

    expect(ViewingRequest::query()->openForRenter($renter)->count())->toBe(0)
        ->and(app(RequestViewing::class)->handle($renter, $properties[0], manila('2027-03-18 09:00'), null)->exists)->toBeTrue()
        ->and(app(RequestViewing::class)->handle($renter, $properties[3], manila('2027-03-18 09:00'), null)->exists)->toBeTrue();
});

it('takes the open limit at the exact moment of the preferred time as closed', function (): void {
    $renter = User::factory()->renter()->create();
    [$property] = listedProperty();
    ViewingRequest::factory()->for($property)->for($renter, 'renter')->accepted()->at(manila('2027-03-15 10:00'))->create();

    expect(ViewingRequest::query()->openForRenter($renter)->count())->toBe(0);

    ViewingRequest::factory()->for($property)->for($renter, 'renter')->accepted()->at(manila('2027-03-15 10:00:01'))->create();

    expect(ViewingRequest::query()->openForRenter($renter)->count())->toBe(1);
});

it('refuses a property that is a draft, archived, or of an unverified account, and a landlord user', function (): void {
    $renter = User::factory()->renter()->create();
    $draft = Property::factory()->at(placeIn('manila'))->create();
    $archived = Property::factory()->published()->at(placeIn('manila'))->create(['archived_at' => now()]);
    $unverified = Property::factory()->published()->at(placeIn('manila'))->create();
    $unverified->landlordAccount()->update(['is_verified' => false]);

    foreach ([$draft, $archived, $unverified] as $property) {
        expect(fn () => app(RequestViewing::class)->handle($renter, $property, manila('2027-03-17 09:00'), null))->toThrow(CannotRequestViewing::class, "isn't open for viewings");
    }

    [$property] = listedProperty();
    expect(fn () => app(RequestViewing::class)->handle(landlordUser(), $property, manila('2027-03-17 09:00'), null))->toThrow(NotARenter::class);
    expect(ViewingRequest::query()->count())->toBe(0);
});

it('serializes one renter: the lock on the renter row is the first statement', function (): void {
    [$property] = listedProperty();
    $renter = User::factory()->renter()->create();
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    app(RequestViewing::class)->handle($renter, $property, manila('2027-03-17 09:00'), null);

    // The landlord members who get the email are looked up after the transaction (slice 6.3): not part of the booking.
    $selects = array_values(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'select') && ! str_contains($sql, 'from `landlord_members`')));

    expect($selects[0])->toContain('from `users`')->toContain('for update');

    foreach ($selects as $select) {
        expect($select)->toMatch('/for update|lock in share mode/', "a non-locking read in RequestViewing: {$select}");
    }
});

// ------------------------------------------------------------------ over HTTP

it('POST /viewings creates the request and lands on my viewings with a confirmation', function (): void {
    [$property] = listedProperty();
    $renter = User::factory()->renter()->create();

    $this->actingAs($renter)->post('/viewings', viewingBody($property, '2027-03-16', '15:30', ['message' => 'Is the rate negotiable?']))
        ->assertRedirect('/viewings')
        ->assertSessionHas('status');

    $viewing = ViewingRequest::query()->where('renter_id', $renter->id)->sole();

    expect($viewing->preferred_at->format('Y-m-d H:i'))->toBe('2027-03-16 07:30')->and($viewing->message)->toBe('Is the rate negotiable?');
});

it('answers JSON with 201, and a broken rule as 422 with the message', function (): void {
    [$property] = listedProperty();
    $renter = User::factory()->renter()->create();

    $this->actingAs($renter)->postJson('/viewings', viewingBody($property))->assertCreated()->assertJsonPath('viewing.status', 'requested');
    $this->actingAs($renter)->postJson('/viewings', viewingBody($property, '2027-03-17'))->assertUnprocessable()->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'open at this property'));
});

it('puts a refusal from the rules on the listing page as the error flash (a plain post)', function (): void {
    [$property] = listedProperty();
    $renter = User::factory()->renter()->create();

    $this->actingAs($renter)->from("/listings/manila/{$property->slug}")->post('/viewings', viewingBody($property, '2027-03-15', '10:00'))
        ->assertRedirect("/listings/manila/{$property->slug}")
        ->assertSessionHas('error');
    expect(ViewingRequest::query()->count())->toBe(0);
});

it('validates the shape: a message over 500 characters, no date, a time that is not a time', function (): void {
    [$property] = listedProperty();
    $renter = User::factory()->renter()->create();

    $this->actingAs($renter)->postJson('/viewings', viewingBody($property, extra: ['message' => str_repeat('a', 501)]))->assertJsonValidationErrors('message');
    $this->actingAs($renter)->postJson('/viewings', ['property_id' => $property->id, 'time' => '09:00'])->assertJsonValidationErrors('date');
    $this->actingAs($renter)->postJson('/viewings', viewingBody($property, time: '9am'))->assertJsonValidationErrors('time');
    $this->actingAs($renter)->postJson('/viewings', viewingBody($property, date: '2027-02-30'))->assertJsonValidationErrors('date');
    $this->actingAs($renter)->postJson('/viewings', viewingBody($property, extra: ['message' => str_repeat('a', 500)]))->assertCreated();
});

it('is a 404 for a property that is not listed, and 403 for a landlord', function (): void {
    $draft = Property::factory()->at(placeIn('manila'))->create();
    [$listed] = listedProperty();

    $this->actingAs(User::factory()->renter()->create())->postJson('/viewings', viewingBody($draft))->assertNotFound();
    $this->actingAs(landlordUser())->postJson('/viewings', viewingBody($listed))->assertForbidden();
    expect(ViewingRequest::query()->count())->toBe(0);
});

it('sends a guest to log in and brings them back to the listing\'s viewing form', function (): void {
    [$property] = listedProperty();

    $this->post('/viewings', viewingBody($property))->assertRedirect('/login');

    expect(session('url.intended'))->toEndWith("/listings/manila/{$property->slug}#viewing");
    expect(ViewingRequest::query()->count())->toBe(0);
});

it('sends an unverified renter to confirm their email, and keeps the listing for afterwards', function (): void {
    [$property] = listedProperty();
    $renter = User::factory()->renter()->create(['email_verified_at' => null]);

    $this->actingAs($renter)->post('/viewings', viewingBody($property))->assertRedirect('/email/verify');

    expect(session('url.intended'))->toEndWith("/listings/manila/{$property->slug}#viewing")
        ->and(ViewingRequest::query()->count())->toBe(0);
});

// ----------------------------------------------------------------- the page

it('puts the form on the listing page with the earliest slot as the default, and nothing else private', function (): void {
    [$property] = listedProperty();

    $this->get("/listings/manila/{$property->slug}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('listing.id', $property->id)
        ->where('viewing.earliest', ['date' => '2027-03-15', 'time' => '12:00'])
        ->where('viewing.dates.0', '2027-03-15')
        ->where('viewing.dates.30', '2027-04-14')
        ->has('viewing.dates', 31)
        ->has('viewing.times', 21)
        ->where('viewing.open', null));
});

it('starts the form at tomorrow after 4 PM', function (): void {
    [$property] = listedProperty();
    $this->travelTo(manila('2027-03-15 16:30'));

    $this->get("/listings/manila/{$property->slug}")->assertInertia(fn ($page) => $page
        ->where('viewing.earliest', ['date' => '2027-03-16', 'time' => '08:00'])
        ->where('viewing.dates.0', '2027-03-16')
        ->has('viewing.dates', 30));
});

it('points a renter with an open request at it instead of offering the form again', function (): void {
    [$property] = listedProperty();
    $renter = User::factory()->renter()->create();
    $viewing = ViewingRequest::factory()->for($property)->for($renter, 'renter')->at(manila('2027-03-17 09:00'))->create();

    $this->actingAs($renter)->get("/listings/manila/{$property->slug}")->assertInertia(fn ($page) => $page->where('viewing.open.id', $viewing->id));
    $this->actingAs(User::factory()->renter()->create())->get("/listings/manila/{$property->slug}")->assertInertia(fn ($page) => $page->where('viewing.open', null));
});

it('renders the form on the server (works without JavaScript) without any contact details', function (): void {
    [$property] = listedProperty();

    $html = $this->get("/listings/manila/{$property->slug}")->assertOk()->getContent();

    expect($html)->toContain('id="viewing"')->toContain('action="/viewings"')->toContain('name="date"')->toContain('name="time"')->toContain('name="message"')->toContain('name="property_id"');
});
