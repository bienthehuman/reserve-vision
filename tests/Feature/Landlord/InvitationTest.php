<?php

declare(strict_types=1);

use App\Actions\CreateInvitation;
use App\Enums\LandlordRole;
use App\Models\LandlordAccount;
use App\Models\LandlordInvitation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

// Slice 3.2: POST /landlord/invitations (owner only) and the members page. No email, no acceptance (4.3).

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/** Posts as an Inertia form would, so a broken rule comes back as the `error` flash. */
function invite(mixed $test, User $as, string $email)
{
    return $test->actingAs($as)->withHeaders(['X-Inertia' => 'true'])->post('/landlord/invitations', ['email' => $email]);
}

function invitationRows(): int
{
    return DB::table('landlord_invitations')->count();
}

it('creates a caretaker invitation: hashed token, 7 days, one audit row, nothing sent', function (): void {
    Mail::fake();
    Notification::fake();
    Bus::fake();
    $owner = landlordUser();

    invite($this, $owner, '  New.Person@Example.COM ')->assertRedirect('/landlord/members')->assertSessionHas('status');

    $invitation = LandlordInvitation::query()->sole();
    expect($invitation)
        ->email->toBe('new.person@example.com')
        ->role->toBe(LandlordRole::Caretaker)
        ->landlord_account_id->toBe(accountIdOf($owner))
        ->invited_by->toBe($owner->id)
        ->token_hash->toMatch('/^[0-9a-f]{64}$/')
        ->accepted_at->toBeNull()
        ->revoked_at->toBeNull();
    expect($invitation->expires_at->equalTo(now()->addDays(7)))->toBeTrue();

    $rows = auditRows();
    expect($rows)->toHaveCount(1)->and($rows[0])
        ->action->toBe('invitation.created')
        ->actor_id->toBe($owner->id)
        ->subject_type->toBe('landlord_invitation')
        ->subject_id->toBe($invitation->id);
    $after = json_decode($rows[0]->after, true);
    expect($after)->toMatchArray(['email' => 'new.person@example.com', 'role' => 'caretaker', 'token_hash' => '[redacted]']);
    expect($rows[0]->after)->not->toContain($invitation->token_hash);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
    Bus::assertNothingDispatched();
});

it('stores the token only as its hash', function (): void {
    $created = app(CreateInvitation::class)->handle(
        LandlordAccount::factory()->create(),
        User::factory()->landlord()->create(),
        'x@example.com',
    );

    expect($created->token)->toHaveLength(48)
        ->and($created->invitation->token_hash)->toBe(hash('sha256', $created->token))
        ->and($created->invitation->token_hash)->not->toContain($created->token)
        ->and(json_encode($created->invitation))->not->toContain('token_hash'); // hidden
});

it('rejects an email that already belongs to a member of any account', function (): void {
    $owner = landlordUser();
    $sameAccount = landlordUserOf(accountIdOf($owner), LandlordRole::Caretaker);
    $otherAccount = landlordUser();

    foreach ([$sameAccount, $otherAccount] as $member) {
        invite($this, $owner, $member->email)->assertRedirect()->assertSessionHas('error', fn (string $m) => str_contains($m, 'already belongs to a member'));
    }

    expect(invitationRows())->toBe(0)->and(auditRows())->toBeEmpty();
});

it('rejects the email of a renter', function (): void {
    $renter = User::factory()->renter()->create(['email' => 'renter@example.com']);

    invite($this, landlordUser(), 'RENTER@example.com')->assertSessionHas('error', fn (string $m) => str_contains($m, 'renter'));

    expect(invitationRows())->toBe(0)->and(auditRows())->toBeEmpty();
    expect($renter->exists)->toBeTrue();
});

it('rejects a second pending invitation but allows one after it expired or was revoked', function (): void {
    $owner = landlordUser();
    $account = accountIdOf($owner);

    invite($this, $owner, 'twice@example.com')->assertSessionHas('status');
    invite($this, $owner, 'Twice@example.com')->assertSessionHas('error', fn (string $m) => str_contains($m, 'already a pending invitation'));
    expect(invitationRows())->toBe(1)->and(auditRows())->toHaveCount(1);

    $this->travelTo(now()->addDays(8)); // the first has expired
    invite($this, $owner, 'twice@example.com')->assertSessionHas('status');

    LandlordInvitation::factory()->revoked()->create(['landlord_account_id' => $account, 'email' => 'revoked@example.com', 'invited_by' => $owner->id]);
    invite($this, $owner, 'revoked@example.com')->assertSessionHas('status');
    expect(invitationRows())->toBe(4);
});

it('lets another account invite an email this account already invited', function (): void {
    invite($this, landlordUser(), 'shared@example.com')->assertSessionHas('status');
    invite($this, landlordUser(), 'shared@example.com')->assertSessionHas('status');

    expect(invitationRows())->toBe(2);
});

it('answers a broken rule as 422 JSON for non-Inertia callers', function (): void {
    User::factory()->renter()->create(['email' => 'renter@example.com']);

    $this->actingAs(landlordUser())->postJson('/landlord/invitations', ['email' => 'renter@example.com'])->assertStatus(422)->assertJsonStructure(['message']);
});

it('validates the email shape', function (string $email): void {
    $this->actingAs(landlordUser())->post('/landlord/invitations', ['email' => $email])->assertSessionHasErrors('email');

    expect(invitationRows())->toBe(0);
})->with(['', 'not-an-email', 'a@', str_repeat('a', 250).'@example.com']);

it('ignores a role sent in the request', function (): void {
    $this->actingAs(landlordUser())->post('/landlord/invitations', ['email' => 'r@example.com', 'role' => 'owner'])->assertRedirect();

    expect(LandlordInvitation::query()->sole()->role)->toBe(LandlordRole::Caretaker);
});

it('refuses a caretaker with 403', function (): void {
    $caretaker = landlordUserOf(accountIdOf(landlordUser()), LandlordRole::Caretaker);

    $this->actingAs($caretaker)->post('/landlord/invitations', ['email' => 'r@example.com'])->assertForbidden();

    expect(invitationRows())->toBe(0)->and(auditRows())->toBeEmpty();
});

it('lists the account\'s members and pending invitations to its owner, and nobody else\'s', function (): void {
    $owner = landlordUser();
    $account = accountIdOf($owner);
    $caretaker = landlordUserOf($account, LandlordRole::Caretaker);
    $stranger = landlordUser();
    LandlordInvitation::factory()->create(['landlord_account_id' => $account, 'email' => 'pending@example.com', 'invited_by' => $owner->id]);
    LandlordInvitation::factory()->accepted()->create(['landlord_account_id' => $account, 'email' => 'accepted@example.com', 'invited_by' => $owner->id]);
    LandlordInvitation::factory()->revoked()->create(['landlord_account_id' => $account, 'email' => 'revoked@example.com', 'invited_by' => $owner->id]);
    LandlordInvitation::factory()->create(['landlord_account_id' => accountIdOf($stranger), 'email' => 'foreign@example.com', 'invited_by' => $stranger->id]);

    $response = $this->actingAs($owner)->get('/landlord/members');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Landlord/Members/Index')
        ->has('members', 2)
        ->where('members.0.name', $owner->full_name)
        ->where('members.0.role', 'owner')
        ->where('members.1.name', $caretaker->full_name)
        ->has('invitations', 1)
        ->where('invitations.0.email', 'pending@example.com')
        ->where('invitations.0.is_expired', false));

    expect($response->getContent())
        ->not->toContain($stranger->full_name)->not->toContain('foreign@example.com')
        ->not->toContain('token_hash')->not->toContain('accepted@example.com');
});

it('keeps the members page from caretakers, renters and guests', function (): void {
    $caretaker = landlordUserOf(accountIdOf(landlordUser()), LandlordRole::Caretaker);

    $this->actingAs($caretaker)->get('/landlord/members')->assertForbidden();
    $this->actingAs(User::factory()->renter()->create())->get('/landlord/members')->assertForbidden();
    auth()->forgetGuards();
    $this->get('/landlord/members')->assertRedirect('/login');
});
