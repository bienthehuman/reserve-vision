<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidLeaseLength;
use App\Exceptions\Domain\NoSpaceAvailable;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

/*
| POST /rooms/{room}/reservations and the hold page (slice 2.5). ReserveSpace's
| rules have their own tests; these cover the HTTP edge. "Now" is 2027-03-15 10:00 Manila.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

it('creates a hold and redirects to the hold page', function (): void {
    $room = Room::factory()->shared(beds: 2)->for(Property::factory()->published())->create(['reservation_fee' => 150000]);
    $renter = User::factory()->create();

    $response = $this->actingAs($renter)
        ->from("/rooms/{$room->id}")
        ->post("/rooms/{$room->id}/reservations", ['move_in' => '2027-04-01', 'months' => 6]);

    $reservation = Reservation::query()->where('renter_id', $renter->id)->sole();
    $response->assertRedirect("/reservations/{$reservation->id}");
    expect($reservation->status)->toBe(ReservationStatus::Held)
        ->and($reservation->move_in->toDateString())->toBe('2027-04-01')
        ->and($reservation->move_out->toDateString())->toBe('2027-10-01');

    $this->get("/reservations/{$reservation->id}")
        ->assertOk()
        ->assertSee('Your hold')
        ->assertSee('Bed A')
        ->assertSee('₱1,500.00')
        // The countdown's server-rendered text, right without JavaScript (slice 5.3).
        ->tap(fn ($response) => expect(preg_replace('/<!-- -->/', '', (string) $response->getContent()))->toMatch('/Your hold ends at 10:00\s(AM|&#x202F;AM)?.{0,12}Tue 16 Mar, Manila time/u'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reservations/Show')
            ->where('reservation.status', 'held')
            ->where('reservation.is_live_hold', true)
            ->where('reservation.space_label', 'Bed A')
            ->where('reservation.reservation_fee', 150000)
            ->where('reservation.hold_expires_at', '2027-03-16T02:00:00+00:00'));
});

it('answers JSON with 201 and the hold', function (): void {
    $room = reservableRoom();

    $this->actingAs(User::factory()->create())
        ->postJson("/rooms/{$room->id}/reservations", ['move_in' => '2027-04-01', 'months' => 6])
        ->assertCreated()
        ->assertJsonPath('reservation.status', 'held')
        ->assertJsonPath('reservation.space_id', onlySpace($room)->id)
        ->assertJsonPath('reservation.hold_expires_at', '2027-03-16T02:00:00+00:00');
});

it('validates the shape only', function (array $input, string $field): void {
    $room = reservableRoom();

    $this->actingAs(User::factory()->create())
        ->from("/rooms/{$room->id}")
        ->post("/rooms/{$room->id}/reservations", $input)
        ->assertRedirect("/rooms/{$room->id}")
        ->assertSessionHasErrors($field);

    expect(Reservation::query()->count())->toBe(0);
})->with([
    'missing move-in' => [['months' => 6], 'move_in'],
    'move-in not a date' => [['move_in' => 'next week', 'months' => 6], 'move_in'],
    'move-in in another format' => [['move_in' => '01/04/2027', 'months' => 6], 'move_in'],
    'impossible date' => [['move_in' => '2027-02-30', 'months' => 6], 'move_in'],
    'missing months' => [['move_in' => '2027-04-01'], 'months'],
    'months not a whole number' => [['move_in' => '2027-04-01', 'months' => '6.5'], 'months'],
    'months too big for an int' => [['move_in' => '2027-04-01', 'months' => '99999999999999999999'], 'months'],
]);

it('leaves the rules to ReserveSpace: out-of-range months is a domain error, not a validation error', function (): void {
    $room = reservableRoom();

    $this->actingAs(User::factory()->create())
        ->postJson("/rooms/{$room->id}/reservations", ['move_in' => '2027-04-01', 'months' => 13])
        ->assertUnprocessable()
        ->assertExactJson(['message' => InvalidLeaseLength::between(1, 12)->getMessage()]);
});

it('renders a domain error as a flash on the room page for Inertia, and 422 JSON otherwise', function (): void {
    $room = reservableRoom();
    reserve(User::factory()->create(), $room, '2027-04-01');
    $renter = User::factory()->create();

    $this->actingAs($renter)
        ->from("/rooms/{$room->id}?move_in=2027-04-01")
        ->withHeaders(['X-Inertia' => 'true'])
        ->post("/rooms/{$room->id}/reservations", ['move_in' => '2027-04-01', 'months' => 6])
        ->assertStatus(303)
        ->assertRedirect("/rooms/{$room->id}?move_in=2027-04-01")
        ->assertSessionHas('error', NoSpaceAvailable::forDates()->getMessage());

    $property = Property::query()->published()->findOrFail($room->property_id);
    $this->flushHeaders()->get("/listings/{$property->city_slug}/{$property->slug}?move_in=2027-04-01")
        ->assertSee('role="alert"', false)
        ->assertSee('This room has no free space');

    $this->postJson("/rooms/{$room->id}/reservations", ['move_in' => '2027-04-01', 'months' => 6])
        ->assertUnprocessable()
        ->assertExactJson(['message' => NoSpaceAvailable::forDates()->getMessage()]);

    expect(Reservation::query()->where('renter_id', $renter->id)->count())->toBe(0);
});

it('sends guests to log in and brings them back to the room on its listing, with the stay kept', function (): void {
    $room = reservableRoom();
    $property = Property::query()->published()->findOrFail($room->property_id);

    $this->from('/somewhere-else')
        ->post("/rooms/{$room->id}/reservations", ['move_in' => '2027-04-01', 'months' => '6'])
        ->assertRedirect('/login')
        ->assertSessionHas('status');

    $intended = url("/listings/{$property->city_slug}/{$property->slug}?move_in=2027-04-01&months=6#room-{$room->id}");
    expect(session('url.intended'))->toBe($intended);

    $renter = User::factory()->create();
    $this->post('/login', ['email' => $renter->email, 'password' => 'password'])
        ->assertRedirect($intended);
});

it('sends an Inertia login on to the room with a full page load, so the #room anchor survives', function (): void {
    $room = reservableRoom();
    $property = Property::query()->published()->findOrFail($room->property_id);
    $this->post("/rooms/{$room->id}/reservations", ['move_in' => '2027-04-01', 'months' => '6']);
    $renter = User::factory()->create();

    $this->withHeaders(['X-Inertia' => 'true'])->post('/login', ['email' => $renter->email, 'password' => 'password'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', url("/listings/{$property->city_slug}/{$property->slug}?move_in=2027-04-01&months=6#room-{$room->id}"));
    $this->flushHeaders();
});

it('keeps an ordinary Inertia login a redirect when there is no anchor to keep', function (): void {
    $renter = User::factory()->create();

    $this->withHeaders(['X-Inertia' => 'true'])->post('/login', ['email' => $renter->email, 'password' => 'password'])
        ->assertRedirect('/');
    $this->flushHeaders();
});

it('keeps only a well-formed stay in the page to come back to', function (): void {
    $room = reservableRoom();

    $this->post("/rooms/{$room->id}/reservations", ['move_in' => 'tomorrow', 'months' => ['x']])->assertRedirect('/login');

    $property = Property::query()->published()->findOrFail($room->property_id);
    expect(session('url.intended'))->toBe(url("/listings/{$property->city_slug}/{$property->slug}#room-{$room->id}"));
});

it('sends an unverified renter to confirm their email, and back to the room after', function (): void {
    $room = reservableRoom();
    $renter = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($renter)->post("/rooms/{$room->id}/reservations", ['move_in' => '2027-04-01', 'months' => 6])
        ->assertRedirect('/email/verify')
        ->assertSessionHas('status', 'Confirm your email first, then you can reserve.');

    $property = Property::query()->published()->findOrFail($room->property_id);
    expect(session('url.intended'))->toBe(url("/listings/{$property->city_slug}/{$property->slug}?move_in=2027-04-01&months=6#room-{$room->id}"))
        ->and(Reservation::query()->count())->toBe(0);
});

it('sends guests asking for JSON a 401', function (): void {
    $this->postJson('/rooms/'.reservableRoom()->id.'/reservations', ['move_in' => '2027-04-01', 'months' => 6])
        ->assertUnauthorized();
});

it('rejects landlords with 403', function (): void {
    $room = reservableRoom();

    $this->actingAs(landlordUser())
        ->post("/rooms/{$room->id}/reservations", ['move_in' => '2027-04-01', 'months' => 6])
        ->assertForbidden();

    expect(Reservation::query()->count())->toBe(0);
});

it('sends renters with an unverified email to verify it first', function (): void {
    $renter = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($renter)
        ->post('/rooms/'.reservableRoom()->id.'/reservations', ['move_in' => '2027-04-01', 'months' => 6])
        ->assertRedirect('/email/verify');
});

it('is a 404 for a room that is not listed', function (): void {
    $room = Room::factory()->archived()->for(Property::factory()->published())->create();

    $this->actingAs(User::factory()->create())
        ->post("/rooms/{$room->id}/reservations", ['move_in' => '2027-04-01', 'months' => 6])
        ->assertNotFound();
});

it('limits each renter to '.AppServiceProvider::RESERVATIONS_PER_MINUTE.' reserve requests a minute', function (): void {
    $room = reservableRoom();
    $renter = User::factory()->create();

    foreach (range(1, AppServiceProvider::RESERVATIONS_PER_MINUTE) as $unused) {
        $this->actingAs($renter)->postJson("/rooms/{$room->id}/reservations", ['move_in' => 'bad', 'months' => 6])
            ->assertUnprocessable();
    }

    $this->actingAs($renter)->postJson("/rooms/{$room->id}/reservations", ['move_in' => '2027-04-01', 'months' => 6])
        ->assertTooManyRequests()
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'too many times'));

    $this->actingAs($renter)->from("/rooms/{$room->id}")
        ->post("/rooms/{$room->id}/reservations", ['move_in' => '2027-04-01', 'months' => 6])
        ->assertStatus(303)
        ->assertSessionHas('error');

    // Another renter has their own budget.
    $this->actingAs(User::factory()->create())->postJson("/rooms/{$room->id}/reservations", ['move_in' => '2027-04-01', 'months' => 6])
        ->assertCreated();
});

it('shows a reservation to its renter only: anyone else gets a 404', function (): void {
    $reservation = reserve(User::factory()->create(), reservableRoom(), '2027-04-01');

    $this->actingAs(User::factory()->create())->get("/reservations/{$reservation->id}")->assertNotFound();
    $this->actingAs(landlordUser())->get("/reservations/{$reservation->id}")->assertForbidden();
    $this->actingAs($reservation->renter()->sole())->get('/reservations/999999')->assertNotFound();
});
