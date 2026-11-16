<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// Slice 3.2: the landlord's reservations list and detail (ACCESS.md matrix rows
// "View reservations for own account" and "See a renter's contact details").

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/** A reservation on a new room of `$accountId`, for a renter with known contact details. */
function reservationIn(int $accountId, string $renterLast = 'Reyes', array $state = [], ?Closure $factory = null): Reservation
{
    $room = Room::factory()->for(Property::factory()->published()->state(['landlord_account_id' => $accountId]))->create();
    $renter = User::factory()->renter()->create([
        'first_name' => 'Ana', 'last_name' => $renterLast,
        'email' => strtolower($renterLast).'@renter.test', 'phone' => '09171234567',
    ]);
    $reservation = Reservation::factory()->for(onlySpace($room), 'space')->for($renter, 'renter');

    return ($factory ? $factory($reservation) : $reservation)->create($state);
}

it('lists the account\'s reservations newest first, for an owner and a caretaker', function (LandlordRole $role): void {
    $owner = landlordUser();
    $account = accountIdOf($owner);
    $user = $role === LandlordRole::Owner ? $owner : landlordUserOf($account, $role);
    $old = reservationIn($account, 'Older', ['created_at' => now()->subDays(3)]);
    $new = reservationIn($account, 'Newer', ['created_at' => now()->subDay()]);
    reservationIn(accountIdOf(landlordUser()), 'Elsewhere');

    $response = $this->actingAs($user)->get('/landlord/reservations');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Landlord/Reservations/Index')
        ->where('reservations.0.id', $new->id)
        ->where('reservations.1.id', $old->id)
        ->has('reservations', 2)
        ->where('reservations.0.renter_name', 'Ana Newer')
        ->has('reservations.0', fn (AssertableInertia $row) => $row
            ->hasAll(['id', 'status', 'renter_name', 'property_name', 'room_name', 'space_label', 'move_in', 'move_out', 'reservation_fee']))
        ->where('pagination.total', 2));

    // Names only: contact details are on the detail page.
    expect($response->getContent())->not->toContain('newer@renter.test')->not->toContain('09171234567')->not->toContain('Elsewhere');
})->with([LandlordRole::Owner, LandlordRole::Caretaker]);

it('filters by status and ignores an unknown one', function (): void {
    $owner = landlordUser();
    $account = accountIdOf($owner);
    $held = reservationIn($account, 'Holding');
    reservationIn($account, 'Confirmed', factory: fn ($f) => $f->confirmed());

    $this->actingAs($owner)->get('/landlord/reservations?status=held')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('reservations', 1)->where('reservations.0.id', $held->id)->where('filter.status', 'held'));

    $this->actingAs($owner)->get('/landlord/reservations?status=nonsense')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('reservations', 2)->where('filter.status', null));
});

it('paginates 15 to a page', function (): void {
    $owner = landlordUser();
    $account = accountIdOf($owner);
    $room = Room::factory()->shared(beds: 16)->for(Property::factory()->published()->state(['landlord_account_id' => $account]))->create();
    $renter = User::factory()->renter()->create();

    foreach ($room->spaces as $space) {
        Reservation::factory()->for($space, 'space')->for($renter, 'renter')->create();
    }

    $this->actingAs($owner)->get('/landlord/reservations')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('reservations', 15)->where('pagination.total', 16)->where('pagination.last_page', 2)->where('pagination.next_url', fn ($url) => str_contains($url, 'page=2')));
    $this->actingAs($owner)->get('/landlord/reservations?page=2')->assertInertia(fn (AssertableInertia $page) => $page->has('reservations', 1));
});

it('shows the renter\'s email and phone, the hold expiry and the proofs on the detail page', function (LandlordRole $role): void {
    $owner = landlordUser();
    $account = accountIdOf($owner);
    $user = $role === LandlordRole::Owner ? $owner : landlordUserOf($account, $role);
    $reservation = reservationIn($account, 'Contact', factory: fn ($f) => $f->proofUploaded());
    PaymentProof::factory()->rejected()->for($reservation)->create(['reference_no' => 'REF-OLD', 'path' => 'proofs/secret-path.jpg']);

    $response = $this->actingAs($user)->get("/landlord/reservations/{$reservation->id}");

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Landlord/Reservations/Show')
        ->where('renter', ['name' => 'Ana Contact', 'email' => 'contact@renter.test', 'phone' => '09171234567'])
        ->where('reservation.hold_expires_at', $reservation->hold_expires_at->toIso8601String())
        ->has('proofs', 2)
        ->has('proofs.0', fn (AssertableInertia $proof) => $proof->hasAll(['id', 'amount', 'reference_no', 'status', 'uploaded_at', 'rejection_reason', 'is_image', 'url'])
            ->where('url', fn (string $url): bool => preg_match('#^/proofs/\d+$#', $url) === 1)));

    // The stored path is never sent: only the /proofs/{id} link, which checks who asks (slice 5.3).
    expect($response->getContent())->not->toContain('secret-path')->not->toContain('proofs/proofs');
})->with([LandlordRole::Owner, LandlordRole::Caretaker]);

it('puts contact details on no other landlord page', function (): void {
    $owner = landlordUser();
    reservationIn(accountIdOf($owner), 'Hidden');

    foreach (['/landlord', '/landlord/reservations', '/landlord/properties', '/landlord/members'] as $path) {
        expect((string) $this->actingAs($owner)->get($path)->getContent())
            ->not->toContain('hidden@renter.test')->not->toContain('09171234567');
    }
});

it('sends guests to log in and renters away', function (): void {
    $reservation = reservationIn(accountIdOf(landlordUser()));

    $this->get('/landlord/reservations')->assertRedirect('/login');
    $this->get("/landlord/reservations/{$reservation->id}")->assertRedirect('/login');
    $this->actingAs(User::factory()->renter()->create())->get('/landlord/reservations')->assertForbidden();
});
