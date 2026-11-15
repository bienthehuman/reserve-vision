<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Exceptions\Isolation\NoCurrentLandlordAccount;
use App\Models\LandlordMember;
use App\Models\Property;
use App\Models\User;
use App\Support\CurrentLandlordAccount;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Isolation\Support\IsolationProbeJob;

// CurrentLandlordAccount (docs/ACCESS.md "Isolation"): resolved once per user
// from the membership, never from the request, and never carried over to the
// next request or job.

/** A test-only route that answers with what the isolation machinery sees. */
function exposeCurrentAccount(): void
{
    $handler = fn (CurrentLandlordAccount $current): array => [
        'account' => $current->id(),
        'properties' => Property::query()->orderBy('id')->pluck('id')->all(),
    ];

    Route::middleware(['web', 'auth', 'landlord'])->group(function () use ($handler): void {
        Route::get('/_isolation/current', $handler);
        Route::post('/_isolation/current', $handler);
    });
}

it('gives the account and role of an owner and of a caretaker', function (LandlordRole $role): void {
    $user = landlordUser(role: $role);
    $member = LandlordMember::query()->where('user_id', $user->id)->sole();

    $this->actingAs($user);
    $current = app(CurrentLandlordAccount::class);

    expect($current->exists())->toBeTrue()
        ->and($current->applies())->toBeTrue()
        ->and($current->id())->toBe($member->landlord_account_id)
        ->and($current->account()->id)->toBe($member->landlord_account_id)
        ->and($current->role())->toBe($role)
        ->and($current->isOwner())->toBe($role === LandlordRole::Owner);
})->with([LandlordRole::Owner, LandlordRole::Caretaker]);

it('throws a clear exception when there is no landlord user', function (?Closure $makeUser): void {
    if ($makeUser !== null) {
        $this->actingAs($makeUser());
    }

    $current = app(CurrentLandlordAccount::class);

    expect($current->exists())->toBeFalse()
        ->and($current->idOrNull())->toBeNull()
        ->and(fn () => $current->id())->toThrow(NoCurrentLandlordAccount::class, 'no landlord user with a membership')
        ->and(fn () => $current->account())->toThrow(NoCurrentLandlordAccount::class)
        ->and(fn () => $current->role())->toThrow(NoCurrentLandlordAccount::class)
        ->and(fn () => $current->isOwner())->toThrow(NoCurrentLandlordAccount::class);
})->with([
    'guest' => [null],
    'renter' => [fn (): User => User::factory()->renter()->create()],
    'landlord user without a membership' => [fn (): User => User::factory()->landlord()->create()],
]);

it('is a scoped instance: one per request or job', function (): void {
    $first = app(CurrentLandlordAccount::class);

    expect(app(CurrentLandlordAccount::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(CurrentLandlordAccount::class))->not->toBe($first);
});

it('gives a landlord user without a membership 403 on landlord pages, not a broken page', function (): void {
    $this->actingAs(User::factory()->landlord()->create())->get('/landlord')->assertForbidden();
});

it('never takes the account from the query, the body or a header', function (): void {
    $owner = landlordUser();
    $own = LandlordMember::query()->where('user_id', $owner->id)->value('landlord_account_id');
    $mine = Property::factory()->create(['landlord_account_id' => $own]);
    $theirs = Property::factory()->create();
    $foreign = $theirs->landlord_account_id;
    exposeCurrentAccount();

    $expected = ['account' => $own, 'properties' => [$mine->id]];
    $this->actingAs($owner);

    $this->getJson("/_isolation/current?landlord_account_id={$foreign}&account={$foreign}")->assertExactJson($expected);
    $this->postJson('/_isolation/current', ['landlord_account_id' => $foreign, 'account_id' => $foreign])->assertExactJson($expected);
    $this->getJson('/_isolation/current', ['X-Landlord-Account-Id' => (string) $foreign, 'X-Account' => (string) $foreign])->assertExactJson($expected);
    $this->get("/landlord?landlord_account_id={$foreign}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('account.slug', $mine->landlordAccount()->sole()->slug));
});

it('doesn\'t carry one landlord\'s account into the next request by another landlord', function (): void {
    $a = landlordUser();
    $b = landlordUser();
    $aAccount = LandlordMember::query()->where('user_id', $a->id)->value('landlord_account_id');
    $bAccount = LandlordMember::query()->where('user_id', $b->id)->value('landlord_account_id');
    $aProperty = Property::factory()->create(['landlord_account_id' => $aAccount]);
    $bProperty = Property::factory()->create(['landlord_account_id' => $bAccount]);
    exposeCurrentAccount();

    $this->actingAs($a)->getJson('/_isolation/current')->assertExactJson(['account' => $aAccount, 'properties' => [$aProperty->id]]);
    $this->actingAs($b)->getJson('/_isolation/current')->assertExactJson(['account' => $bAccount, 'properties' => [$bProperty->id]]);
    $this->actingAs($a)->getJson('/_isolation/current')->assertExactJson(['account' => $aAccount, 'properties' => [$aProperty->id]]);
});

it('doesn\'t carry a landlord from one job into the next on the same worker', function (): void {
    $a = landlordUser();
    $b = landlordUser();
    $aAccount = LandlordMember::query()->where('user_id', $a->id)->value('landlord_account_id');
    $bAccount = LandlordMember::query()->where('user_id', $b->id)->value('landlord_account_id');
    $aProperty = Property::factory()->create(['landlord_account_id' => $aAccount]);
    $bProperty = Property::factory()->create(['landlord_account_id' => $bAccount]);
    IsolationProbeJob::$seen = [];

    // A careless job logs landlord A in; the next jobs must not inherit A.
    dispatch(new IsolationProbeJob('first', logInAs: $a->id))->onConnection('database');
    dispatch(new IsolationProbeJob('second'))->onConnection('database');
    dispatch(new IsolationProbeJob('third', logInAs: $b->id))->onConnection('database');
    $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true])->assertExitCode(0);

    expect(IsolationProbeJob::$seen['first'])->toBe(['applies' => true, 'account' => $aAccount, 'properties' => [$aProperty->id]])
        ->and(IsolationProbeJob::$seen['second']['applies'])->toBeFalse()
        ->and(IsolationProbeJob::$seen['second']['account'])->toBeNull()
        ->and(IsolationProbeJob::$seen['second']['properties'])->toContain($aProperty->id, $bProperty->id)
        ->and(IsolationProbeJob::$seen['third'])->toBe(['applies' => true, 'account' => $bAccount, 'properties' => [$bProperty->id]]);
});
