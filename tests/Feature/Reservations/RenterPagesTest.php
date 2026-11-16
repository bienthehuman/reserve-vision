<?php

declare(strict_types=1);

use App\Models\LandlordAccount;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

/*
| The renter's pages (slice 5.3): the hold page and "my reservations".
*/

beforeEach(function (): void {
    fakeMediaDisks();
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

it('shows a live hold with the countdown, where to pay, the landlord\'s name and phone, the form and Cancel', function (): void {
    [$property, $room] = listedProperty();
    LandlordAccount::query()->whereKey($property->landlord_account_id)->update(['name' => 'Lorna Rentals', 'contact_phone' => '09171112222']);
    $hold = liveHold(User::factory()->renter()->create(), $room);

    $response = $this->actingAs($hold->renter)->get("/reservations/{$hold->id}");

    $response->assertOk()
        ->assertSee('GCash 0917 000 0000, ask for Ate Lorna')
        ->assertSee('Lorna Rentals')
        ->assertSee('09171112222')
        ->assertSee('Send your proof of payment')
        ->assertSee('name="_token"', false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reservations/Show')
            ->where('reservation.status', 'held')
            ->where('reservation.can_upload', true)
            ->where('reservation.can_cancel', true)
            ->where('property.house_rules', 'No visitors after 10 PM. The reservation fee is refunded if we decline.')
            ->where('property.listing_url', '/listings/manila/casa-verde')
            ->where('landlord', ['name' => 'Lorna Rentals', 'contact_phone' => '09171112222'])
            ->where('proofs', []));

    // A plain multipart form, so it posts before hydration too (React writes the attribute as `encType`; browsers don't mind).
    expect((string) $response->getContent())->toMatch('/enctype="multipart\/form-data"/i');
});

it('shows the proofs sent, with their links, and no form once the proof is uploaded', function (): void {
    $reservation = Reservation::factory()->proofUploaded()->for(User::factory()->renter()->create(), 'renter')->create();
    PaymentProof::factory()->rejected()->for($reservation)->create(['uploaded_at' => now()->subDay(), 'path' => 'proofs/the-stored-path.jpg']);

    $response = $this->actingAs($reservation->renter)->get("/reservations/{$reservation->id}");

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reservation.status', 'proof_uploaded')
        ->where('reservation.can_upload', false)
        ->where('reservation.can_cancel', true)
        ->where('property.payment_instructions', null)
        ->has('proofs', 2)
        ->where('proofs.0.status', 'pending')
        ->where('proofs.1.status', 'rejected')
        ->where('proofs.1.rejection_reason', 'The amount does not match.')
        ->where('proofs.0.url', fn (string $url): bool => preg_match('#^/proofs/\d+$#', $url) === 1));

    expect($response->getContent())->not->toContain('the-stored-path');
});

it('offers no Cancel for a stay that has started, or a final reservation', function (string $state): void {
    $reservation = Reservation::factory()->{$state}()->for(User::factory()->renter()->create(), 'renter')->create();

    $this->actingAs($reservation->renter)->get("/reservations/{$reservation->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('reservation.can_cancel', false));
})->with(['active', 'cancelled', 'declined', 'ended', 'expired']);

it('lists my reservations: current first (soonest move-in first), then past, only mine', function (): void {
    $renter = User::factory()->renter()->create();
    $mine = fn (string $state, string $moveIn) => Reservation::factory()->{$state}()->for($renter, 'renter')
        ->create(['move_in' => $moveIn, 'move_out' => CarbonImmutable::parse($moveIn)->addMonths(3)->toDateString()]);

    $later = $mine('confirmed', '2027-05-01');
    $soon = $mine('proofUploaded', '2027-04-01');
    $live = $mine('held', '2027-04-15');
    $expiredHold = $mine('expiredHold', '2027-04-20');
    $cancelled = $mine('cancelled', '2027-06-01');
    Reservation::factory()->held()->for(User::factory()->renter()->create(), 'renter')->create();

    $this->actingAs($renter)->get('/reservations')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Reservations/Index')
        ->where('current', fn ($rows) => collect($rows)->pluck('id')->all() === [$soon->id, $live->id, $later->id])
        ->where('current.1.hold_expires_at', fn ($at) => is_string($at))
        ->where('past', fn ($rows) => collect($rows)->pluck('id')->all() === [$cancelled->id, $expiredHold->id])
        ->where('past.1.status', 'expired')
        ->where('pagination.total', 2));
});

it('pages the past reservations, ten at a time', function (): void {
    $renter = User::factory()->renter()->create();
    Reservation::factory()->count(12)->cancelled()->for($renter, 'renter')->create();

    $this->actingAs($renter)->get('/reservations')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('past', 10)->where('pagination.last_page', 2));
    $this->actingAs($renter)->get('/reservations?page=2')->assertInertia(fn (AssertableInertia $page) => $page->has('past', 2));
});

it('keeps my reservations to renters: a landlord gets 403, a guest the login page', function (): void {
    $this->actingAs(landlordUser())->get('/reservations')->assertForbidden();
    app('auth')->forgetGuards();
    $this->get('/reservations')->assertRedirect('/login');
});

it('shows renters the My reservations link in the account menu', function (): void {
    $renter = User::factory()->renter()->create();

    $this->actingAs($renter)->get('/')->assertOk()->assertSee('My reservations');
    app('auth')->forgetGuards();
    $this->actingAs(landlordUser())->get('/')->assertOk()->assertDontSee('My reservations');
});

it('a property that was unpublished since gets no listing link', function (): void {
    $reservation = Reservation::factory()->confirmed()->for(User::factory()->renter()->create(), 'renter')->create();
    Property::query()->whereKey($reservation->space->room->property_id)->update(['is_published' => false]);

    $this->actingAs($reservation->renter)->get("/reservations/{$reservation->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('property.listing_url', null));
});
