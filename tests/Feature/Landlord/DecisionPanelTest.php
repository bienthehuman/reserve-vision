<?php

declare(strict_types=1);

use App\Actions\RejectProof;
use App\Actions\WithdrawReservation;
use App\Models\PaymentProof;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

/*
| Slice 6.1: what the landlord reservation page offers (App\Support\Reservations\DecisionPanel) and what the
| renter's pages show after each landlord move. Actions are DecisionsTest's; `toDecide()` is defined there.
*/

beforeEach(function (): void {
    fakeMediaDisks();
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/** The decision panel the landlord page gets, as `$role`. */
function panelFor(mixed $test, array $w, string $role): array
{
    return $test->actingAs($w[$role])->get("/landlord/reservations/{$w['reservation']->id}")->assertOk()->viewData('page')['props']['decisions'];
}

it('offers confirm, reject and decline on a proof waiting, to both roles', function (string $role): void {
    $panel = panelFor($this, toDecide('proofUploaded'), $role);

    expect($panel['moves'])->toBe([
        ['key' => 'confirm', 'allowed' => true], ['key' => 'reject', 'allowed' => true], ['key' => 'decline', 'allowed' => true],
    ])->and($panel['rejections_left'])->toBe(2)->and($panel['refund'])->toBeNull();
})->with(['owner', 'caretaker']);

it('hides reject after two rejections, and says so with rejections_left 0', function (): void {
    $panel = panelFor($this, toDecide('proofUploaded', ['proof_rejections' => 2]), 'owner');

    expect(array_column($panel['moves'], 'key'))->toBe(['confirm', 'decline'])->and($panel['rejections_left'])->toBe(0);
});

it('on a confirmed stay: undo within the window, moving in disabled until the date, withdraw for owners only', function (): void {
    $w = toDecide('confirmed', ['decided_at' => now()->subHours(2)]);

    $owner = panelFor($this, $w, 'owner');
    expect($owner['moves'])->toBe([
        ['key' => 'moved_in', 'allowed' => false], ['key' => 'undo', 'allowed' => true], ['key' => 'withdraw', 'allowed' => true],
    ])->and($owner['undo_until'])->toBe(now()->addHours(22)->toIso8601String())
        ->and($owner['owner_only'])->toBe([])
        ->and($owner['today'])->toBe('2027-03-15');

    $caretaker = panelFor($this, $w, 'caretaker');
    expect(array_column($caretaker['moves'], 'key'))->toBe(['moved_in', 'undo'])->and($caretaker['owner_only'])->toBe(['withdraw']);
});

it('on the move-in date: moving in allowed, undo and withdraw disabled', function (): void {
    $panel = panelFor($this, toDecide('confirmed', ['decided_at' => now()->subHour()], moveIn: '2027-03-15'), 'owner');

    expect($panel['moves'])->toBe([
        ['key' => 'moved_in', 'allowed' => true], ['key' => 'undo', 'allowed' => false], ['key' => 'withdraw', 'allowed' => false],
    ]);
});

it('offers end stay on an active stay, and nothing on a final one', function (): void {
    expect(panelFor($this, toDecide('active', moveIn: '2027-02-15'), 'caretaker')['moves'])->toBe([['key' => 'end_stay', 'allowed' => true]]);

    $this->app['auth']->forgetGuards();
    expect(panelFor($this, toDecide('ended', moveIn: '2026-11-01'), 'owner')['moves'])->toBe([]);
});

it('shows the refund card on a declined or cancelled reservation that had a proof, not on one without', function (): void {
    $declined = toDecide('declined');
    expect(panelFor($this, $declined, 'caretaker')['refund'])->toBe(['can_record' => true, 'fee_refunded' => null, 'refund_note' => null]);

    $this->app['auth']->forgetGuards();
    $cancelled = toDecide('cancelled');
    expect(panelFor($this, $cancelled, 'owner')['refund'])->toBeNull();
});

it('says "Hold ended" only for a hold, not once a proof is in (the 5.3 backlog item): the page gets the status to decide', function (): void {
    $w = toDecide('proofUploaded', ['hold_expires_at' => now()->subHours(3)]);

    $this->actingAs($w['owner'])->get("/landlord/reservations/{$w['reservation']->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('reservation.status', 'proof_uploaded')->where('reservation.is_live_hold', false));

    // Rendered by SSR: no hold line for a proof waiting.
    expect($this->get("/landlord/reservations/{$w['reservation']->id}")->getContent())->not->toContain('Hold ended');
});

it('names who decided and why on the landlord page', function (): void {
    $w = toDecide('confirmed');
    app(WithdrawReservation::class)->handle($w['owner'], $w['reservation'], 'The roof is being repaired.');

    $this->actingAs($w['caretaker'])->get("/landlord/reservations/{$w['reservation']->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reservation.decision_reason', 'The roof is being repaired.')
            ->where('reservation.cancelled_by_renter', false)
            ->where('reservation.cancelled_by', $w['owner']->full_name));
});

/*
| The renter's side
*/

it('shows the renter the rejection reason, a new countdown and the upload form after a proof is sent back', function (): void {
    $w = toDecide('proofUploaded');
    app(RejectProof::class)->handle($w['owner'], $w['reservation'], 'The amount is ₱1,000; the fee is ₱1,500.');
    $renter = $w['reservation']->renter;
    $this->app['auth']->forgetGuards();

    $response = $this->actingAs($renter)->get("/reservations/{$w['reservation']->id}");
    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reservation.status', 'held')
        ->where('reservation.can_upload', true)
        ->where('reservation.proof_rejections', 1)
        ->where('reservation.hold_expires_at', now()->addHours(24)->toIso8601String())
        ->where('proofs.0.status', 'rejected')
        ->where('proofs.0.rejection_reason', 'The amount is ₱1,000; the fee is ₱1,500.'));

    expect(str_replace('<!-- -->', '', (string) $response->getContent()))
        ->toContain('Send a new proof of payment')->toContain('the fee is ₱1,500.')->toContain('Send your proof of payment');

    $this->get('/reservations')->assertInertia(fn (AssertableInertia $page) => $page->where('current.0.new_proof_needed', true)->where('current.0.withdrawn', false));
});

it('shows the renter a decline or a withdrawal with the reason, and the refund once recorded', function (string $state, string $route, string $heading): void {
    $w = toDecide($state);
    $this->actingAs($w['owner'])->post("/landlord/reservations/{$w['reservation']->id}/{$route}", ['reason' => 'The roof is being repaired.']);
    $renter = $w['reservation']->renter;
    $this->app['auth']->forgetGuards();

    $page = fn () => str_replace('<!-- -->', '', (string) $this->actingAs($renter)->get("/reservations/{$w['reservation']->id}")->getContent());
    expect($page())->toContain($heading)->toContain('The roof is being repaired.')->toContain('ask ')->not->toContain('says the fee was');

    $this->get('/reservations')->assertInertia(fn (AssertableInertia $p) => $p->where('past.0.withdrawn', $route === 'withdraw'));

    if (PaymentProof::query()->where('reservation_id', $w['reservation']->id)->doesntExist()) {
        PaymentProof::factory()->for($w['reservation'])->create();
    }
    $this->app['auth']->forgetGuards();
    $this->actingAs($w['owner'])->put("/landlord/reservations/{$w['reservation']->id}/refund", ['fee_refunded' => '1', 'refund_note' => 'Sent back by GCash on Mar 15.']);
    $this->app['auth']->forgetGuards();

    expect($page())->toContain('says the fee was refunded.')->toContain('Sent back by GCash on Mar 15.');
})->with([
    'declined' => ['proofUploaded', 'decline', 'declined your reservation'],
    'withdrawn' => ['confirmed', 'withdraw', 'withdrew your reservation'],
]);

it('shows the renter a confirmation', function (): void {
    $w = toDecide('proofUploaded');
    $this->actingAs($w['caretaker'])->post("/landlord/reservations/{$w['reservation']->id}/confirm");
    $renter = $w['reservation']->renter;
    $this->app['auth']->forgetGuards();

    expect(str_replace('<!-- -->', '', (string) $this->actingAs($renter)->get("/reservations/{$w['reservation']->id}")->getContent()))
        ->toContain('Your reservation is confirmed')->toContain('You can move in on Apr 1, 2027.');
});
