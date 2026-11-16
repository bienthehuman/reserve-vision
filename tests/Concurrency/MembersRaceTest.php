<?php

declare(strict_types=1);

use App\Actions\CreateInvitation;
use App\Enums\LandlordRole;
use App\Exceptions\Domain\CannotRemoveMember;
use App\Exceptions\Domain\InvitationNotValid;
use App\Models\LandlordMember;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Tests\Concurrency\Support\Exchange;
use Tests\Concurrency\Support\Race;
use Tests\Concurrency\Support\Racer;

/*
| Slice 4.3, through real, parallel HTTP requests (see ReservationRaceTest.php for the harness).
|
| 1. Three people open the same invitation link and submit the sign-up form at the same moment.
|    AcceptInvitation locks the invitation row first, so they run one after another: exactly ONE user
|    and ONE membership exist afterwards, and the others get the neutral "isn't valid any more" answer.
|
| 2. Two owners of one account remove each other at the same moment. RemoveMember locks every member
|    row of the account first and reads the rules from those rows: exactly ONE owner is left. The
|    second is refused: 422 "no longer an owner" if their request got as far as the action, 403 if the
|    first removal had committed before their request reached the `landlord` middleware.
|
| The invitation routes allow 10 requests a minute per address (and every racer here is 127.0.0.1), so
| the accept race is 3 rounds of 3: 9 page loads and 9 accepts.
*/

const ACCEPT_ROUNDS = 3;
const ACCEPT_RACERS = 3;
const REMOVE_ROUNDS = 5;

it('accept race: 3 people sign up through the same invitation link at once, and exactly one joins', function (): void {
    expect(ACCEPT_ROUNDS * ACCEPT_RACERS)->toBeLessThanOrEqual(AppServiceProvider::INVITATIONS_PER_MINUTE, 'more accepts than the rate limit allows from one address');

    $round = nextRound('Accept');
    $owner = landlordUser();
    $account = $owner->landlordMember()->sole()->landlordAccount;
    $email = sprintf('caretaker-%d-%s@example.com', $round, bin2hex(random_bytes(4)));
    $created = app(CreateInvitation::class)->handle($account, $owner, $email);

    // Guests: each loads the page first, for a session and its CSRF token.
    $guests = array_map(fn (int $i): Racer => new Racer(new User, sprintf('guest-%02d', $i)), range(1, ACCEPT_RACERS));
    $pages = array_map(fn (Racer $guest): Exchange => $guest->get("/invitations/{$created->token}"), $guests);
    $this->http->fire($pages);
    foreach ($pages as $page) {
        expect($page->status)->toBe(200, 'The invitation page should open: '.$page->describe());
    }

    $accepts = array_map(fn (Racer $guest, int $i): Exchange => $guest->postJson("/invitations/{$created->token}", [
        'first_name' => "Racer{$i}", 'last_name' => 'Reyes', 'phone' => '09175550199',
        'password' => 'correct horse battery', 'password_confirmation' => 'correct horse battery',
    ]), $guests, array_keys($guests));

    $race = Race::run(
        $this->http,
        sprintf('Accept race round %d/%d: %d people sign up through one invitation link', $round, ACCEPT_ROUNDS, ACCEPT_RACERS),
        $accepts,
        fn (Exchange $e): string => $e->status === 201 ? 'joined' : ($e->message() ?: 'HTTP '.$e->status),
    );

    $users = User::query()->where('email', $email)->get();
    $caretakers = LandlordMember::query()->where('landlord_account_id', $account->id)->where('role', LandlordRole::Caretaker)->get();

    expect($users)->toHaveCount(1, "DOUBLE-JOINED: {$users->count()} users were created for one invitation")
        ->and($caretakers)->toHaveCount(1, "DOUBLE-JOINED: {$caretakers->count()} memberships were created for one invitation")
        ->and($caretakers[0]->user_id)->toBe($users[0]->id)
        ->and($created->invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($race->withStatus(201))->toHaveCount(1);

    foreach ($accepts as $accept) {
        if ($accept->status !== 201) {
            expect($accept->status)->toBe(422, 'Unexpected response: '.$accept->describe())
                ->and($accept->message())->toBe(InvitationNotValid::MESSAGE);
        }
    }
})->repeat(ACCEPT_ROUNDS);

it('remove race: two owners remove each other at once, and exactly one owner is left', function (): void {
    $round = nextRound('Remove');
    $a = new Racer(landlordUser(), 'owner-a');
    $accountId = accountIdOf($a->user);
    $b = new Racer(landlordUserOf($accountId), 'owner-b');
    Racer::logInAll($this->http, [$a, $b]);

    $removeB = new Exchange($a, 'DELETE', '/landlord/members/'.$b->user->landlordMember()->sole()->id, [], json: true);
    $removeA = new Exchange($b, 'DELETE', '/landlord/members/'.$a->user->landlordMember()->sole()->id, [], json: true);

    Race::run(
        $this->http,
        sprintf('Remove race round %d/%d: two owners remove each other', $round, REMOVE_ROUNDS),
        [$removeB, $removeA],
        fn (Exchange $e): string => match ($e->status) {
            200 => 'removed the other owner',
            422 => $e->message(),
            default => 'HTTP '.$e->status,
        },
    );

    $owners = LandlordMember::query()->where('landlord_account_id', $accountId)->where('role', LandlordRole::Owner)->pluck('user_id')->all();

    expect($owners)->toHaveCount(1, 'NO OWNER LEFT or BOTH LEFT: the account has '.count($owners).' owners after two owners removed each other');

    [$winner, $loser] = $owners[0] === $a->user->id ? [$removeB, $removeA] : [$removeA, $removeB];

    expect($winner->status)->toBe(200, 'Unexpected response: '.$winner->describe())
        ->and($loser->status)->toBeIn([403, 422], 'Unexpected response: '.$loser->describe());

    if ($loser->status === 422) {
        expect($loser->message())->toBe(CannotRemoveMember::notAnOwnerAnyMore()->getMessage());
    }
})->repeat(REMOVE_ROUNDS);
