<?php

declare(strict_types=1);

use App\Actions\AcceptViewing;
use App\Enums\LandlordRole;
use App\Enums\ViewingRequestStatus;
use App\Exceptions\Domain\InvalidViewingMove;
use App\Models\Property;
use App\Models\User;
use App\Models\ViewingRequest;
use Carbon\CarbonImmutable;

/*
| The landlord's side of flow C (slice 6.2): accept, decline, change an answer until the time, mark done from it.
| "Now" is Monday 2027-03-15 10:00 Manila.
*/

beforeEach(function (): void {
    fakeMediaDisks();
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/**
 * An account with an owner and a caretaker, and a viewing of one of its properties in `$state` at `$moment` (Manila).
 *
 * @return array{User, User, ViewingRequest}
 */
function viewingInAccount(string $state = 'requested', string $moment = '2027-03-17 09:00'): array
{
    $owner = landlordUser();
    $caretaker = landlordUserOf(accountIdOf($owner), LandlordRole::Caretaker);
    $property = Property::factory()->published()->at(placeIn('manila'))->create(['landlord_account_id' => accountIdOf($owner)]);
    $viewing = ViewingRequest::factory()->for($property)->for(User::factory()->renter()->create(), 'renter')
        ->at(CarbonImmutable::parse($moment, 'Asia/Manila'))->state(['message' => 'Can I bring my mother?'])
        ->{$state}()->create();

    return [$owner, $caretaker, $viewing];
}

it('accepts a requested viewing with a note, as owner or caretaker, and audits it', function (string $role): void {
    [$owner, $caretaker, $viewing] = viewingInAccount();
    $actor = $role === 'owner' ? $owner : $caretaker;

    $this->actingAs($actor)->post("/landlord/viewings/{$viewing->id}/accept", ['note' => "Ring the bell at gate 2.\n"])
        ->assertRedirect("/landlord/viewings/{$viewing->id}")->assertSessionHas('status');

    $fresh = $viewing->fresh();
    $rows = auditRows('viewing.accepted');

    expect($fresh?->status)->toBe(ViewingRequestStatus::Accepted)
        ->and($fresh?->landlord_note)->toBe('Ring the bell at gate 2.')
        ->and($fresh?->decided_by)->toBe($actor->id)
        ->and($rows)->toHaveCount(1)
        ->and($rows->first()->actor_id)->toBe($actor->id)
        ->and($rows->first()->subject_type)->toBe('viewing_request')
        ->and(json_decode($rows->first()->after, true))->toEqual(['status' => 'accepted', 'landlord_note' => 'Ring the bell at gate 2.', 'decided_by' => $actor->id])
        ->and(json_decode($rows->first()->before, true))->toEqual(['status' => 'requested', 'landlord_note' => null, 'decided_by' => null]);
})->with(['owner', 'caretaker']);

it('declines with no note at all', function (): void {
    [$owner, , $viewing] = viewingInAccount();

    $this->actingAs($owner)->post("/landlord/viewings/{$viewing->id}/decline")->assertRedirect();

    expect($viewing->fresh()?->status)->toBe(ViewingRequestStatus::Declined)->and($viewing->fresh()?->landlord_note)->toBeNull()
        ->and(auditRows('viewing.declined'))->toHaveCount(1);
});

it('changes an answer until preferred_at: accept to decline and back, replacing the note', function (): void {
    [$owner, $caretaker, $viewing] = viewingInAccount();

    $this->actingAs($owner)->post("/landlord/viewings/{$viewing->id}/accept", ['note' => 'Yes, come.'])->assertRedirect();
    $this->actingAs($caretaker)->post("/landlord/viewings/{$viewing->id}/decline", ['note' => 'Sorry, the room was just taken.'])->assertRedirect();

    expect($viewing->fresh()?->status)->toBe(ViewingRequestStatus::Declined)->and($viewing->fresh()?->landlord_note)->toBe('Sorry, the room was just taken.');

    $this->actingAs($owner)->post("/landlord/viewings/{$viewing->id}/accept", ['note' => 'Actually it is free again.'])->assertRedirect();

    expect($viewing->fresh()?->status)->toBe(ViewingRequestStatus::Accepted)->and($viewing->fresh()?->decided_by)->toBe($owner->id)
        ->and(auditRows())->toHaveCount(3)
        ->and(auditRows()->pluck('action')->all())->toBe(['viewing.accepted', 'viewing.declined', 'viewing.accepted']);
});

it('refuses to answer the same way twice', function (): void {
    [$owner, , $viewing] = viewingInAccount('accepted');

    $this->actingAs($owner)->postJson("/landlord/viewings/{$viewing->id}/accept")->assertUnprocessable()->assertJsonPath('message', fn (string $m): bool => str_contains($m, "can't become accepted"));
    expect(auditRows())->toBeEmpty();
});

it('refuses to accept, decline or change an answer once preferred_at has come', function (string $state, string $path): void {
    [$owner, , $viewing] = viewingInAccount($state, '2027-03-15 10:00');

    $this->actingAs($owner)->postJson("/landlord/viewings/{$viewing->id}/{$path}")->assertUnprocessable()->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'has passed'));

    expect($viewing->fresh()?->status->value)->toBe($state)->and(auditRows())->toBeEmpty();
})->with([
    'accept a requested one' => ['requested', 'accept'],
    'decline a requested one' => ['requested', 'decline'],
    'decline an accepted one' => ['accepted', 'decline'],
    'accept a declined one' => ['declined', 'accept'],
]);

it('allows an answer up to the last second before preferred_at', function (): void {
    [$owner, , $viewing] = viewingInAccount('requested', '2027-03-15 10:00:01');

    $this->actingAs($owner)->post("/landlord/viewings/{$viewing->id}/accept")->assertRedirect();

    expect($viewing->fresh()?->status)->toBe(ViewingRequestStatus::Accepted);
});

it('marks an accepted viewing done from preferred_at on, not before', function (): void {
    [$owner, $caretaker, $viewing] = viewingInAccount('accepted', '2027-03-15 10:00:01');

    $this->actingAs($owner)->postJson("/landlord/viewings/{$viewing->id}/done")->assertUnprocessable()->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'from its preferred time'));
    expect($viewing->fresh()?->status)->toBe(ViewingRequestStatus::Accepted);

    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00:01', 'Asia/Manila'));
    $this->actingAs($caretaker)->post("/landlord/viewings/{$viewing->id}/done")->assertRedirect();

    expect($viewing->fresh()?->status)->toBe(ViewingRequestStatus::Done)->and($viewing->fresh()?->landlord_note)->toBeNull()
        ->and(auditRows('viewing.marked_done'))->toHaveCount(1);
});

it('keeps the note when a viewing is marked done', function (): void {
    [$owner, , $viewing] = viewingInAccount('accepted', '2027-03-14 09:00');
    $viewing->update(['landlord_note' => 'Gate 2.']);

    $this->actingAs($owner)->post("/landlord/viewings/{$viewing->id}/done")->assertRedirect();

    expect($viewing->fresh()?->landlord_note)->toBe('Gate 2.');
});

it('refuses done on a requested, declined or cancelled viewing, even long after the time', function (string $state): void {
    [$owner, , $viewing] = viewingInAccount($state, '2027-03-10 09:00');

    $this->actingAs($owner)->postJson("/landlord/viewings/{$viewing->id}/done")->assertUnprocessable();
    expect($viewing->fresh()?->status->value)->toBe($state);
})->with(['requested', 'declined', 'cancelled']);

it('cannot move a done or cancelled viewing anywhere', function (string $state): void {
    [$owner, , $viewing] = viewingInAccount($state);

    foreach (['accept', 'decline', 'done'] as $path) {
        $this->actingAs($owner)->postJson("/landlord/viewings/{$viewing->id}/{$path}")->assertUnprocessable();
    }

    expect($viewing->fresh()?->status->value)->toBe($state)->and(auditRows())->toBeEmpty();
})->with(['done', 'cancelled']);

it('validates the note: at most 500 characters', function (): void {
    [$owner, , $viewing] = viewingInAccount();

    $this->actingAs($owner)->postJson("/landlord/viewings/{$viewing->id}/accept", ['note' => str_repeat('a', 501)])->assertJsonValidationErrors('note');
    expect($viewing->fresh()?->status)->toBe(ViewingRequestStatus::Requested);
    $this->actingAs($owner)->postJson("/landlord/viewings/{$viewing->id}/accept", ['note' => str_repeat('a', 500)])->assertOk()->assertJsonPath('status', 'accepted');
});

it('locks the viewing row first and re-reads the status from it (a stale model changes nothing)', function (): void {
    [$owner, , $viewing] = viewingInAccount();
    $stale = ViewingRequest::query()->findOrFail($viewing->id);
    $viewing->update(['status' => ViewingRequestStatus::Cancelled]);

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    $this->actingAs($owner);
    expect(fn () => app(AcceptViewing::class)->handle($owner, $stale))->toThrow(InvalidViewingMove::class);

    $lock = array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'from `viewing_requests`')));

    expect($lock[0])->toContain('for update')->and(auditRows())->toBeEmpty();
});

it('answers 404 for another account\'s viewing and 403 for a renter, changing nothing', function (): void {
    [, , $viewing] = viewingInAccount();
    [$otherOwner] = viewingInAccount();

    foreach (['accept', 'decline', 'done'] as $path) {
        $this->actingAs($otherOwner)->postJson("/landlord/viewings/{$viewing->id}/{$path}")->assertNotFound();
        $this->actingAs($viewing->renter)->postJson("/landlord/viewings/{$viewing->id}/{$path}")->assertForbidden();
    }

    $this->actingAs($otherOwner)->get("/landlord/viewings/{$viewing->id}")->assertNotFound();
    $this->actingAs($viewing->renter)->get("/landlord/viewings/{$viewing->id}")->assertForbidden();
    $this->actingAs($viewing->renter)->get('/landlord/viewings')->assertForbidden();
    expect($viewing->fresh()?->status)->toBe(ViewingRequestStatus::Requested)->and(auditRows())->toBeEmpty();
});

it('a guest is sent to log in', function (): void {
    [, , $viewing] = viewingInAccount();

    $this->get('/landlord/viewings')->assertRedirect('/login');
    $this->post("/landlord/viewings/{$viewing->id}/accept")->assertRedirect('/login');
});

// ----------------------------------------------------------------- the pages

it('shows the renter\'s email and phone on the request\'s page, and not on the list', function (): void {
    [$owner, $caretaker, $viewing] = viewingInAccount();
    $renter = $viewing->renter;

    foreach ([$owner, $caretaker] as $actor) {
        $this->actingAs($actor)->get("/landlord/viewings/{$viewing->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('Landlord/Viewings/Show')
            ->where('renter.name', $renter->full_name)->where('renter.email', $renter->email)->where('renter.phone', $renter->phone)
            ->where('viewing.message', 'Can I bring my mother?')
            ->where('viewing.is_upcoming', true));
    }

    $list = $this->actingAs($owner)->get('/landlord/viewings')->assertOk();
    expect($list->getContent())->not->toContain($renter->email)->not->toContain($renter->phone);
});

it('offers the moves the table allows, and whether the clock allows them now', function (): void {
    [$owner, , $requested] = viewingInAccount('requested', '2027-03-17 09:00');
    [$owner1, , $accepted] = viewingInAccount('accepted', '2027-03-17 09:00');
    [$owner2, , $past] = viewingInAccount('accepted', '2027-03-14 09:00');
    [$owner3, , $done] = viewingInAccount('done', '2027-03-14 09:00');

    $moves = fn (User $actor, ViewingRequest $viewing) => $this->actingAs($actor)->get("/landlord/viewings/{$viewing->id}")->viewData('page')['props']['moves'];

    expect($moves($owner, $requested))->toBe([['key' => 'accept', 'allowed' => true], ['key' => 'decline', 'allowed' => true]])
        ->and($moves($owner1, $accepted))->toBe([['key' => 'decline', 'allowed' => true], ['key' => 'done', 'allowed' => false]])
        ->and($moves($owner2, $past))->toBe([['key' => 'decline', 'allowed' => false], ['key' => 'done', 'allowed' => true]])
        ->and($moves($owner3, $done))->toBe([]);
});

it('lists today\'s viewings first, marked, then the coming ones soonest first, then the past', function (): void {
    $owner = landlordUser();
    $property = Property::factory()->published()->at(placeIn('manila'))->create(['landlord_account_id' => accountIdOf($owner)]);
    $make = function (string $moment, string $state = 'requested') use ($property): ViewingRequest {
        return ViewingRequest::factory()->for($property)->for(User::factory()->renter()->create(), 'renter')->at(CarbonImmutable::parse($moment, 'Asia/Manila'))->{$state}()->create();
    };

    $later = $make('2027-03-20 09:00');
    $soon = $make('2027-03-16 09:00');
    $todayLate = $make('2027-03-15 17:00', 'accepted');
    $todayEarly = $make('2027-03-15 12:00');
    $todayDeclined = $make('2027-03-15 14:00', 'declined'); // not open, so not marked "today": it sorts among the coming ones
    $earlierToday = $make('2027-03-15 09:00'); // already past
    $yesterday = $make('2027-03-14 09:00', 'accepted');

    $page = $this->actingAs($owner)->get('/landlord/viewings')->viewData('page')['props'];

    expect(array_column($page['viewings'], 'id'))->toBe([$todayEarly->id, $todayLate->id, $todayDeclined->id, $soon->id, $later->id, $earlierToday->id, $yesterday->id]);
    expect(array_column($page['viewings'], 'is_today'))->toBe([true, true, false, false, false, false, false]);
});

it('filters by status and by the dashboard\'s list', function (): void {
    $owner = landlordUser();
    $property = Property::factory()->published()->at(placeIn('manila'))->create(['landlord_account_id' => accountIdOf($owner)]);
    $make = fn (string $moment, string $state) => ViewingRequest::factory()->for($property)->for(User::factory()->renter()->create(), 'renter')->at(CarbonImmutable::parse($moment, 'Asia/Manila'))->{$state}()->create();

    $waiting = $make('2027-03-16 09:00', 'requested');
    $make('2027-03-16 10:00', 'accepted');
    $make('2027-03-10 10:00', 'requested'); // nobody answered, the time has gone: not waiting
    $declined = $make('2027-03-16 11:00', 'declined');

    $ids = fn (string $query) => array_column($this->actingAs($owner)->get("/landlord/viewings{$query}")->viewData('page')['props']['viewings'], 'id');

    expect($ids('?show=waiting'))->toBe([$waiting->id])
        ->and($ids('?status=declined'))->toBe([$declined->id])
        ->and($ids('?status=nonsense'))->toHaveCount(4);

    $this->actingAs($owner)->get('/landlord')->assertInertia(fn ($page) => $page->where('figures.viewings.count', 1));
});

it('shows only the account\'s own viewings, on the list and the dashboard figure', function (): void {
    [$owner, , $mine] = viewingInAccount();
    [, , $theirs] = viewingInAccount();

    $this->actingAs($owner)->get('/landlord/viewings')->assertInertia(fn ($page) => $page->has('viewings', 1)->where('viewings.0.id', $mine->id));
    $this->actingAs($owner)->get('/landlord')->assertInertia(fn ($page) => $page->where('figures.viewings.count', 1));
    expect($theirs->id)->not->toBe($mine->id);
});
