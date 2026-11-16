<?php

declare(strict_types=1);

use App\Actions\CreateInvitation;
use App\Actions\RemoveMember;
use App\Enums\LandlordRole;
use App\Exceptions\Domain\CannotRemoveMember;
use App\Models\LandlordMember;
use App\Models\Property;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

// Slice 4.3: DELETE /landlord/members/{member} (owner only). The last owner can't be removed; a removed
// person keeps their login and gets 403 on every landlord page. The race of two owners removing each
// other over real parallel HTTP is in tests/Concurrency/MembersRaceTest.php.

it('removes a caretaker: the row goes, one audit row, and their next request is refused', function (): void {
    $owner = landlordUser();
    $account = accountIdOf($owner);
    $caretaker = landlordUserOf($account, LandlordRole::Caretaker);
    $member = $caretaker->landlordMember()->sole();
    $property = Property::factory()->published()->create(['landlord_account_id' => $account]);

    $this->actingAs($caretaker)->get('/landlord')->assertOk();
    $this->actingAs($caretaker)->get("/landlord/properties/{$property->id}")->assertOk();

    $this->actingAs($owner)->delete("/landlord/members/{$member->id}")
        ->assertRedirect('/landlord/members')->assertSessionHas('status', "{$caretaker->full_name} is removed from this account.");

    expect(LandlordMember::query()->whereKey($member->id)->exists())->toBeFalse();

    $row = auditRows('member.removed')->sole();
    expect($row->actor_id)->toBe($owner->id)
        ->and($row->landlord_account_id)->toBe($account)
        ->and($row->subject_type)->toBe('landlord_member')
        ->and($row->subject_id)->toBe($member->id)
        ->and(json_decode($row->before, true))->toEqual(['user_id' => $caretaker->id, 'role' => 'caretaker']);

    // They keep their login, and every landlord page is now a 403 (3.1 fails closed), read or write.
    $removed = $caretaker->fresh();
    $this->actingAs($removed)->get('/landlord')->assertForbidden();
    $this->actingAs($removed)->get('/landlord/reservations')->assertForbidden();
    $this->actingAs($removed)->get('/landlord/properties')->assertForbidden();
    $this->actingAs($removed)->post('/landlord/invitations', ['email' => 'x@example.com'])->assertForbidden();
    // A page of one record is refused a step earlier: the record is looked up first, and the scope finds nothing for them (404).
    $this->actingAs($removed)->get("/landlord/properties/{$property->id}")->assertNotFound();
    $this->actingAs($removed)->post("/landlord/properties/{$property->id}/archive")->assertNotFound();
    expect($property->fresh()->archived_at)->toBeNull();
    $this->post('/logout')->assertRedirect();
    $this->post('/login', ['email' => $removed->email, 'password' => 'password'])->assertRedirect('/landlord');
    $this->get('/landlord')->assertForbidden();
});

it('lets an owner remove another owner, and the removed owner can be invited back', function (): void {
    Mail::fake();
    $owner = landlordUser();
    $account = accountIdOf($owner);
    $other = landlordUserOf($account);

    $this->actingAs($owner)->delete('/landlord/members/'.$other->landlordMember()->sole()->id)->assertRedirect();

    expect(LandlordMember::query()->where('landlord_account_id', $account)->pluck('user_id')->all())->toBe([$owner->id]);

    // Re-invite (DATA_MODEL "Remove member → Re-invite"): they come back as a caretaker.
    $created = app(CreateInvitation::class)->handle($owner->landlordMember()->sole()->landlordAccount, $owner, $other->email);
    $this->actingAs($other->fresh())->post("/invitations/{$created->token}")->assertRedirect('/landlord');
    expect($other->landlordMember()->sole()->role)->toBe(LandlordRole::Caretaker);
});

it('never removes the last owner, whoever asks and however', function (): void {
    $owner = landlordUser();
    $account = accountIdOf($owner);
    $caretaker = landlordUserOf($account, LandlordRole::Caretaker);
    $ownerRow = $owner->landlordMember()->sole();

    // Themself, through the page's route.
    $this->actingAs($owner)->deleteJson("/landlord/members/{$ownerRow->id}")->assertStatus(422)
        ->assertJsonPath('message', CannotRemoveMember::yourself()->getMessage());

    // A caretaker can't (403, T7). And the action itself refuses when the target is the only owner,
    // even for an actor who is an owner in the rows it locked (a second owner removed in the same breath).
    $this->actingAs($caretaker)->deleteJson("/landlord/members/{$ownerRow->id}")->assertForbidden();

    $second = landlordUserOf($account);
    $secondRow = $second->landlordMember()->sole();
    DB::table('landlord_members')->where('id', $secondRow->id)->update(['role' => 'caretaker']);
    expect(fn () => app(RemoveMember::class)->handle($ownerRow, $second))->toThrow(CannotRemoveMember::class, 'no longer an owner');

    expect(LandlordMember::query()->where('landlord_account_id', $account)->where('role', 'owner')->count())->toBe(1)
        ->and(auditRows('member.removed'))->toBeEmpty();
});

it('two owners removing each other, one after the other: the second is refused and one owner is left', function (): void {
    $a = landlordUser();
    $account = accountIdOf($a);
    $b = landlordUserOf($account);
    [$rowA, $rowB] = [$a->landlordMember()->sole(), $b->landlordMember()->sole()];

    // Both requests passed the policy while both were owners; then the actions run.
    app(RemoveMember::class)->handle($rowB, $a);
    expect(fn () => app(RemoveMember::class)->handle($rowA, $b))->toThrow(CannotRemoveMember::class);

    expect(LandlordMember::query()->where('landlord_account_id', $account)->pluck('user_id')->all())->toBe([$a->id])
        ->and(auditRows('member.removed'))->toHaveCount(1);
});

it('refuses to remove the only owner when asked by an owner the rows no longer hold', function (): void {
    $owner = landlordUser();
    $ghost = landlordUserOf(accountIdOf($owner));
    $ghost->landlordMember()->delete();

    expect(fn () => app(RemoveMember::class)->handle($owner->landlordMember()->sole(), $ghost))
        ->toThrow(CannotRemoveMember::class, CannotRemoveMember::notAnOwnerAnyMore()->getMessage());
});

it('does nothing when the member was already removed', function (): void {
    $owner = landlordUser();
    $caretaker = landlordUserOf(accountIdOf($owner), LandlordRole::Caretaker);
    $member = $caretaker->landlordMember()->sole();

    app(RemoveMember::class)->handle($member, $owner);
    app(RemoveMember::class)->handle($member, $owner);

    expect(auditRows('member.removed'))->toHaveCount(1);
});

it('locks every member row of the account before it checks anything', function (): void {
    $owner = landlordUser();
    $caretaker = landlordUserOf(accountIdOf($owner), LandlordRole::Caretaker);
    $member = $caretaker->landlordMember()->sole();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });
    app(RemoveMember::class)->handle($member, $owner);

    expect($queries[0])->toMatch('/^select \* from `landlord_members` where `landlord_account_id` = \? order by `id` asc for update$/')
        ->and(array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'select') && ! str_ends_with($sql, 'for update')))->toBeEmpty();
});

it('answers 404 for another account\'s member, and removes nobody', function (): void {
    $owner = landlordUser();
    $stranger = landlordUserOf(accountIdOf(landlordUser()), LandlordRole::Caretaker);

    $this->actingAs($owner)->delete('/landlord/members/'.$stranger->landlordMember()->sole()->id)->assertNotFound();

    expect($stranger->landlordMember()->exists())->toBeTrue();
});

it('shows the owner who can be removed: everyone but themself', function (): void {
    $owner = landlordUser();
    $account = accountIdOf($owner);
    landlordUserOf($account, LandlordRole::Caretaker);
    landlordUserOf($account);

    $this->actingAs($owner)->get('/landlord/members')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('members', 3)
        ->where('members.0.is_you', true)->where('members.0.can_remove', false)
        ->where('members.1.is_you', false)->where('members.1.can_remove', true)
        ->where('members.2.can_remove', true));
});
