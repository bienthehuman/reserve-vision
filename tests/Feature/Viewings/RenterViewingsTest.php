<?php

declare(strict_types=1);

use App\Actions\CancelViewing;
use App\Enums\ViewingRequestStatus;
use App\Exceptions\Domain\InvalidViewingMove;
use App\Models\Property;
use App\Models\User;
use App\Models\ViewingRequest;
use Carbon\CarbonImmutable;

/*
| The renter's side of flow C (slice 6.2): my viewings, cancelling, and what an accepted request shows.
*/

beforeEach(function (): void {
    fakeMediaDisks();
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

function myViewing(User $renter, string $state = 'requested', string $moment = '2027-03-17 09:00', ?Property $property = null): ViewingRequest
{
    $property ??= Property::factory()->published()->at(placeIn('manila'))->create(['name' => 'Casa Alba', 'address' => '7 Mabini St']);

    return ViewingRequest::factory()->for($property)->for($renter, 'renter')->at(CarbonImmutable::parse($moment, 'Asia/Manila'))->{$state}()->create();
}

it('cancels a requested or accepted viewing before its time, with no audit row', function (string $state): void {
    $renter = User::factory()->renter()->create();
    $viewing = myViewing($renter, $state);

    $this->actingAs($renter)->post("/viewings/{$viewing->id}/cancel")->assertRedirect('/viewings')->assertSessionHas('status');

    expect($viewing->fresh()?->status)->toBe(ViewingRequestStatus::Cancelled)->and(auditRows())->toBeEmpty();
})->with(['requested', 'accepted']);

it('refuses to cancel from the preferred time on, or a declined, done or cancelled one', function (string $state, string $moment): void {
    $renter = User::factory()->renter()->create();
    $viewing = myViewing($renter, $state, $moment);

    $this->actingAs($renter)->postJson("/viewings/{$viewing->id}/cancel")->assertUnprocessable();

    expect($viewing->fresh()?->status->value)->toBe($state);
})->with([
    'requested, the time has come' => ['requested', '2027-03-15 10:00'],
    'accepted, the time has passed' => ['accepted', '2027-03-14 09:00'],
    'declined' => ['declined', '2027-03-17 09:00'],
    'done' => ['done', '2027-03-14 09:00'],
    'cancelled' => ['cancelled', '2027-03-17 09:00'],
]);

it('frees the open limit and the property when cancelled', function (): void {
    $renter = User::factory()->renter()->create();
    $viewing = myViewing($renter);

    expect(ViewingRequest::query()->openForRenter($renter)->count())->toBe(1);

    app(CancelViewing::class)->handle($renter, $viewing);

    expect(ViewingRequest::query()->openForRenter($renter)->count())->toBe(0);
});

it('locks the viewing row and re-reads it: a stale model that was answered meanwhile still cancels, a cancelled one is refused', function (): void {
    $renter = User::factory()->renter()->create();
    $viewing = myViewing($renter);
    $stale = ViewingRequest::query()->findOrFail($viewing->id);
    $viewing->update(['status' => ViewingRequestStatus::Cancelled]);

    expect(fn () => app(CancelViewing::class)->handle($renter, $stale))->toThrow(InvalidViewingMove::class);
});

it('is a 404 for another renter\'s viewing, on the cancel and nowhere else', function (): void {
    $mine = User::factory()->renter()->create();
    $theirs = myViewing(User::factory()->renter()->create());

    $this->actingAs($mine)->postJson("/viewings/{$theirs->id}/cancel")->assertNotFound();
    expect($theirs->fresh()?->status)->toBe(ViewingRequestStatus::Requested);
    $this->actingAs(landlordUser())->postJson("/viewings/{$theirs->id}/cancel")->assertForbidden();
    auth()->logout();
    $this->post("/viewings/{$theirs->id}/cancel")->assertRedirect('/login');
});

it('lists mine: coming up first (soonest first), then past, and never someone else\'s', function (): void {
    $renter = User::factory()->renter()->create();
    $later = myViewing($renter, 'requested', '2027-03-25 09:00');
    $soon = myViewing($renter, 'accepted', '2027-03-16 09:00');
    $declined = myViewing($renter, 'declined', '2027-03-17 09:00');
    $gone = myViewing($renter, 'requested', '2027-03-14 09:00'); // nobody answered, the time went by
    $done = myViewing($renter, 'done', '2027-03-01 09:00');
    myViewing(User::factory()->renter()->create());

    $props = $this->actingAs($renter)->get('/viewings')->assertOk()->assertInertia(fn ($page) => $page->component('Viewings/Index'))->viewData('page')['props'];

    expect(array_column($props['current'], 'id'))->toBe([$soon->id, $later->id])
        ->and(array_column($props['past'], 'id'))->toBe([$declined->id, $gone->id, $done->id])
        ->and($props['past'][1])->toMatchArray(['is_upcoming' => false, 'can_cancel' => false]);
});

it('shows the landlord\'s phone and the full address only once accepted', function (): void {
    $renter = User::factory()->renter()->create();
    $property = Property::factory()->published()->at(placeIn('manila'))->create(['address' => '7 Mabini St']);
    $property->landlordAccount()->update(['contact_phone' => '09171234567']);
    $requested = myViewing($renter, 'requested', '2027-03-17 09:00', $property);

    $row = fn (int $id) => collect($this->actingAs($renter)->get('/viewings')->viewData('page')['props']['current'])->firstWhere('id', $id);

    expect($row($requested->id)['meeting'])->toBeNull();
    expect(json_encode($this->actingAs($renter)->get('/viewings')->viewData('page')['props']))->not->toContain('09171234567')->not->toContain('7 Mabini St');

    $requested->update(['status' => ViewingRequestStatus::Accepted, 'landlord_note' => 'See you at the gate.']);

    expect($row($requested->id)['meeting'])->toMatchArray(['phone' => '09171234567'])
        ->and($row($requested->id)['meeting']['address'])->toContain('7 Mabini St')
        ->and($row($requested->id)['landlord_note'])->toBe('See you at the gate.');

    $requested->update(['status' => ViewingRequestStatus::Declined]);
    $declined = collect($this->actingAs($renter)->get('/viewings')->viewData('page')['props']['past'])->firstWhere('id', $requested->id);

    expect($declined['meeting'])->toBeNull();
});

it('still shows a viewing of a property that was unpublished since, without a link to its listing', function (): void {
    $renter = User::factory()->renter()->create();
    $viewing = myViewing($renter, 'accepted');
    $viewing->property->update(['is_published' => false]);

    $row = $this->actingAs($renter)->get('/viewings')->viewData('page')['props']['current'][0];

    expect($row['id'])->toBe($viewing->id)->and($row['listing_url'])->toBeNull()->and($row['can_cancel'])->toBeTrue();
});

it('is for renters: a landlord gets 403 and a guest is sent to log in', function (): void {
    $this->actingAs(landlordUser())->get('/viewings')->assertForbidden();
    auth()->logout();
    $this->get('/viewings')->assertRedirect('/login');
});

it('adds My viewings to a renter\'s menu: the page the link goes to loads', function (): void {
    $this->actingAs(User::factory()->renter()->create())->get('/viewings')->assertOk();
});
