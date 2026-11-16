<?php

declare(strict_types=1);

use App\Actions\AcceptInvitation;
use App\Actions\CreateInvitation;
use App\Data\NewInvitation;
use App\Data\RegistrationDetails;
use App\Enums\LandlordRole;
use App\Enums\UserType;
use App\Exceptions\Domain\InvitationNotValid;
use App\Models\LandlordInvitation;
use App\Models\LandlordMember;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

// Slice 4.3: GET and POST /invitations/{token} (ACCESS.md "Accepting an invitation"). Public, rate limited.
// One neutral page for every link that can't be used; four ways on for one that can. The race of two
// accepts over real parallel HTTP is in tests/Concurrency/MembersRaceTest.php.

beforeEach(function (): void {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/** An owner of "Santos Rentals" and a fresh invitation for `$email`, with its plain token. */
function invitationFor(string $email = 'carla@example.com'): NewInvitation
{
    $owner = landlordUser();
    $account = $owner->landlordMember()->sole()->landlordAccount;
    $account->forceFill(['name' => 'Santos Rentals'])->save();

    return app(CreateInvitation::class)->handle($account, $owner, $email);
}

/** The short sign-up form of the invitation page. */
function joinForm(array $overrides = []): array
{
    return [
        'first_name' => 'Carla', 'middle_name' => '', 'last_name' => 'Reyes', 'suffix' => null, 'phone' => '0917 555 0199',
        'password' => 'correct horse battery', 'password_confirmation' => 'correct horse battery',
        ...$overrides,
    ];
}

/** Asserts the one neutral page: 404, the one sentence, and nothing about any invitation. */
function expectNeutralPage(mixed $response): void
{
    $response->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Invitations/Show')
        ->where('state', 'invalid')
        ->where('reason', InvitationNotValid::MESSAGE)
        ->where('invitation', null));

    expect((string) $response->getContent())->not->toContain('Santos Rentals')->not->toContain('carla@example.com');
}

it('shows one neutral page for an unknown, expired, revoked or already accepted link, without saying which', function (string $case): void {
    $created = invitationFor();

    match ($case) {
        'expired' => $this->travel(8)->days(),
        'revoked' => $created->invitation->forceFill(['revoked_at' => now()])->save(),
        'accepted' => $created->invitation->forceFill(['accepted_at' => now()])->save(),
        default => null,
    };

    $response = $this->get('/invitations/'.($case === 'unknown' ? Str::random(48) : $created->token));

    expectNeutralPage($response);
    expect($response->getContent())->toContain('This invitation link isn'); // SSR escapes the apostrophe
})->with(['unknown', 'expired', 'revoked', 'accepted']);

it('gives exactly the same body for every kind of unusable link', function (): void {
    $bodies = [];

    foreach (['unknown', 'expired', 'revoked', 'accepted'] as $case) {
        $invitation = LandlordInvitation::factory()->{$case === 'unknown' ? 'expired' : $case}()->create(['token_hash' => hash('sha256', "token{$case}")]);
        $props = $this->get('/invitations/'.($case === 'unknown' ? 'nosuchtoken' : "token{$case}"))->assertNotFound()->viewData('page')['props'];
        $bodies[] = json_encode([$props['state'], $props['reason'], $props['invitation']]);
        $invitation->delete();
    }

    expect(array_unique($bodies))->toHaveCount(1);
});

it('answers a token that is not letters and digits with a plain 404, before any lookup', function (): void {
    $this->get('/invitations/abc-def')->assertNotFound();
    $this->get('/invitations/'.str_repeat('a', 129))->assertNotFound();
});

it('token guessing: random tokens get the neutral page, and the 11th try in a minute from one address is refused', function (): void {
    $created = invitationFor();

    foreach (range(1, AppServiceProvider::INVITATIONS_PER_MINUTE) as $try) {
        expectNeutralPage($this->get('/invitations/'.Str::random(48)));
    }

    // Even the real token is refused now: the limit is per address, not per token.
    $this->get('/invitations/'.Str::random(48))->assertTooManyRequests();
    $this->get("/invitations/{$created->token}")->assertTooManyRequests();

    // Accepting has its own bucket, with the same limit; over it a form post goes back with a message.
    foreach (range(1, AppServiceProvider::INVITATIONS_PER_MINUTE) as $try) {
        $this->postJson('/invitations/'.Str::random(48), joinForm())->assertStatus(422)->assertExactJson(['message' => InvitationNotValid::MESSAGE]);
    }
    $this->postJson('/invitations/'.Str::random(48), joinForm())->assertTooManyRequests();
    $this->withHeaders(['X-Inertia' => 'true'])->post("/invitations/{$created->token}", joinForm())->assertSessionHas('error');
    $this->flushHeaders(); // withHeaders() sticks to the test case; with built assets the next GET would be a 409

    expect(LandlordMember::query()->where('landlord_account_id', $created->invitation->landlord_account_id)->count())->toBe(1);

    // Another address is not affected, and a minute later neither is the first.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get("/invitations/{$created->token}")->assertOk();
    $this->travel(61)->seconds();
    $this->get("/invitations/{$created->token}")->assertOk();
});

it('shows a guest with a new email the account, the role, who invited them, and the sign-up form', function (): void {
    $created = invitationFor();

    $this->get("/invitations/{$created->token}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Invitations/Show')
        ->where('state', 'register')
        ->where('invitation.account', 'Santos Rentals')
        ->where('invitation.role', 'caretaker')
        ->where('invitation.invited_by', $created->invitation->inviter->full_name)
        ->where('invitation.email', 'carla@example.com')
        ->where('invitation.accept_url', "/invitations/{$created->token}"));
});

it('signs a new person up through the link: a verified landlord user, the caretaker membership, logged in, on /landlord', function (): void {
    $created = invitationFor();

    $this->post("/invitations/{$created->token}", joinForm(['email' => 'someone.else@example.com', 'type' => 'renter', 'role' => 'owner']))
        ->assertRedirect('/landlord')->assertSessionHas('status', 'You\'ve joined Santos Rentals as a caretaker.');

    $user = User::query()->where('email', 'carla@example.com')->sole(); // the invited email, whatever the form sent
    expect($user)
        ->type->toBe(UserType::Landlord)
        ->first_name->toBe('Carla')
        ->phone->toBe('09175550199')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(User::query()->where('email', 'someone.else@example.com')->exists())->toBeFalse();

    $member = $user->landlordMember()->sole();
    expect($member->landlord_account_id)->toBe($created->invitation->landlord_account_id)
        ->and($member->role)->toBe(LandlordRole::Caretaker)
        ->and($created->invitation->fresh()->accepted_at?->equalTo(now()))->toBeTrue();

    $this->assertAuthenticatedAs($user);
    $this->get('/landlord')->assertOk();
    $this->get('/landlord/members')->assertForbidden(); // a caretaker

    // Audited on the account, with the new member as the actor.
    $row = auditRows('invitation.accepted')->sole();
    expect($row->actor_id)->toBe($user->id)
        ->and($row->landlord_account_id)->toBe($created->invitation->landlord_account_id)
        ->and($row->subject_id)->toBe($created->invitation->id)
        ->and(json_decode($row->after, true))->toMatchArray(['member_id' => $member->id, 'user_id' => $user->id, 'role' => 'caretaker']);

    // No "confirm your email" mail: the link proved the inbox.
    Mail::assertNothingQueued();
});

it('validates the sign-up form like /register, and creates nothing on a mistake', function (array $overrides, string $field): void {
    $created = invitationFor();

    $this->post("/invitations/{$created->token}", joinForm($overrides))->assertSessionHasErrors($field);

    expect(User::query()->where('email', 'carla@example.com')->exists())->toBeFalse()
        ->and($created->invitation->fresh()->accepted_at)->toBeNull();
    $this->assertGuest();
})->with([
    'no first name' => [['first_name' => ''], 'first_name'],
    'no last name' => [['last_name' => ''], 'last_name'],
    'a landline' => [['phone' => '(02) 8123 4567'], 'phone'],
    'a short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'passwords differ' => [['password_confirmation' => 'something else entirely'], 'password'],
    'an unknown suffix' => [['suffix' => 'Esq.'], 'suffix'],
]);

it('rolls everything back when the membership can\'t be made', function (): void {
    $created = invitationFor();
    // The last write but two fails: the user that was just created must go with it.
    LandlordMember::creating(fn () => throw new RuntimeException('no membership today'));

    expect(fn () => app(AcceptInvitation::class)->handle($created->token, null, new RegistrationDetails('Carla', null, 'Reyes', null, 'x@example.com', '09175550199', 'correct horse battery')))
        ->toThrow(RuntimeException::class);

    expect(User::query()->where('email', 'carla@example.com')->exists())->toBeFalse()
        ->and($created->invitation->fresh()->accepted_at)->toBeNull()
        ->and(auditRows('invitation.accepted'))->toBeEmpty();

    LandlordMember::flushEventListeners();
});

it('asks a guest whose email already has an account to log in, and brings them back to the link', function (): void {
    $created = invitationFor();
    $user = User::factory()->landlord()->create(['email' => 'carla@example.com']);

    $this->get("/invitations/{$created->token}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'login'));

    // Signing up through the link is refused: the account exists.
    $this->withHeaders(['X-Inertia' => 'true'])->post("/invitations/{$created->token}", joinForm())->assertSessionHas('error');
    $this->flushHeaders(); // withHeaders() sticks to the test case
    expect(User::query()->where('email', 'carla@example.com')->count())->toBe(1);

    $this->post('/login', ['email' => 'carla@example.com', 'password' => 'password'])->assertRedirect("/invitations/{$created->token}");

    $this->get("/invitations/{$created->token}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'join'));
    $this->post("/invitations/{$created->token}")->assertRedirect('/landlord');

    expect($user->landlordMember()->sole()->role)->toBe(LandlordRole::Caretaker);
});

it('lets a logged-in landlord user with the invited email and no account join with one button', function (): void {
    $created = invitationFor();
    // A removed member: a landlord user, no membership, and (to prove the link verifies it) an unconfirmed email.
    $user = User::factory()->landlord()->create(['email' => 'Carla@Example.com', 'email_verified_at' => null]);

    $this->actingAs($user)->get("/invitations/{$created->token}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'join')->where('invitation.account', 'Santos Rentals'));
    $this->actingAs($user)->get('/landlord')->assertRedirect('/email/verify');

    $this->actingAs($user)->post("/invitations/{$created->token}")->assertRedirect('/landlord');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and($user->landlordMember()->sole()->landlord_account_id)->toBe($created->invitation->landlord_account_id)
        ->and(auditRows('invitation.accepted')->sole()->actor_id)->toBe($user->id)
        ->and(auditRows('invitation.accepted')->sole()->landlord_account_id)->toBe($created->invitation->landlord_account_id);

    // The same request cycle sees the new membership: the landlord pages open, scoped to the account just joined.
    $this->actingAs($user->fresh())->get('/landlord')->assertOk();
    $this->actingAs($user->fresh())->get('/landlord/properties')->assertOk();
});

it('refuses a renter with the invited email, with a clear message', function (): void {
    $created = invitationFor();
    // They signed up as a renter after the invitation was made (CreateInvitation refuses a renter's email).
    $renter = User::factory()->renter()->create(['email' => 'carla@example.com']);

    $this->actingAs($renter)->get("/invitations/{$created->token}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('state', 'refused')->where('reason', fn (string $reason): bool => str_contains($reason, 'renter account')));

    $this->actingAs($renter)->postJson("/invitations/{$created->token}")->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'renter account'));

    expect(LandlordMember::query()->where('user_id', $renter->id)->exists())->toBeFalse()
        ->and($renter->fresh()->type)->toBe(UserType::Renter)
        ->and($created->invitation->fresh()->accepted_at)->toBeNull();
});

it('refuses someone who already belongs to a landlord account', function (): void {
    $created = invitationFor();
    // They became an owner elsewhere after the invitation was made.
    $member = landlordUser();
    $member->forceFill(['email' => 'carla@example.com'])->save();

    $this->actingAs($member)->get("/invitations/{$created->token}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('state', 'refused')->where('reason', fn (string $reason): bool => str_contains($reason, 'only one')));

    $this->actingAs($member)->postJson("/invitations/{$created->token}")->assertStatus(422);

    expect(LandlordMember::query()->where('user_id', $member->id)->count())->toBe(1)
        ->and($created->invitation->fresh()->accepted_at)->toBeNull();
});

it('tells someone logged in with another email so, offers to log out, and never lets them join', function (User $other): void {
    $created = invitationFor();

    $this->actingAs($other)->get("/invitations/{$created->token}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('state', 'other_user')->where('invitation.email', 'carla@example.com'));

    $this->actingAs($other)->postJson("/invitations/{$created->token}")->assertStatus(422)
        ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'another email address'));

    expect(LandlordMember::query()->where('landlord_account_id', $created->invitation->landlord_account_id)->count())->toBe(1)
        ->and($created->invitation->fresh()->accepted_at)->toBeNull();
})->with([
    'a renter' => fn () => User::factory()->renter()->create(),
    'a landlord user without an account' => fn () => User::factory()->landlord()->create(),
    'an owner of another account' => fn () => landlordUser(),
]);

it('accepts a link once: the second accept gets the neutral answer and there is one membership', function (): void {
    $created = invitationFor();

    $this->post("/invitations/{$created->token}", joinForm())->assertRedirect('/landlord');
    auth()->logout();

    $this->post("/invitations/{$created->token}", joinForm(['first_name' => 'Second']))->assertRedirect("/invitations/{$created->token}");
    $this->postJson("/invitations/{$created->token}", joinForm())->assertStatus(422)->assertExactJson(['message' => InvitationNotValid::MESSAGE]);
    expectNeutralPage($this->get("/invitations/{$created->token}"));

    expect(LandlordMember::query()->where('landlord_account_id', $created->invitation->landlord_account_id)->where('role', 'caretaker')->count())->toBe(1)
        ->and(User::query()->where('first_name', 'Second')->exists())->toBeFalse()
        ->and(auditRows('invitation.accepted'))->toHaveCount(1);
});

it('locks the invitation row first, then the user row, with locking reads', function (): void {
    $created = invitationFor();
    $user = User::factory()->landlord()->create(['email' => 'carla@example.com']);
    $this->actingAs($user);

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });
    app(AcceptInvitation::class)->handle($created->token, $user);

    expect($queries[0])->toMatch('/^select \* from `landlord_invitations` where `landlord_invitations`\.`token_hash` = \? limit 1 for update$/')
        ->and($queries[1])->toMatch('/^select \* from `users` where `users`\.`id` = \? limit 1 for update$/')
        ->and($queries[2])->toMatch('/^select \* from `landlord_members` where `user_id` = \? limit 1 for update$/');
});

it('one person, two invitations: the second account\'s link is refused once they have joined the first', function (): void {
    $first = invitationFor();
    $second = invitationFor();

    $this->post("/invitations/{$first->token}", joinForm())->assertRedirect('/landlord');
    $this->postJson("/invitations/{$second->token}")->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'only one'));

    expect(LandlordMember::query()->whereHas('user', fn ($q) => $q->where('email', 'carla@example.com'))->count())->toBe(1);
});
