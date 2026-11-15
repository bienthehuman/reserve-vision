<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Models\LandlordInvitation;
use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| T7 (BUILD_PLAN.md): a caretaker tries to change a rate or invite a member.
| Expected: 403, nothing changed, no audit row. The owner can do both, and each
| writes exactly one audit row. (Another account's records are T6: 404, not 403.)
*/

/** An account with an owner, a caretaker and a room at ₱6,500. */
function t7Account(): array
{
    $owner = landlordUser();
    $account = accountIdOf($owner);
    $caretaker = landlordUserOf($account, LandlordRole::Caretaker);
    $room = Room::factory()->for(Property::factory()->published()->state(['landlord_account_id' => $account]))->create(['monthly_rate' => 650000]);

    return [$owner, $caretaker, $room];
}

/** The tables a write could touch, as one comparable value. */
function t7State(): string
{
    return md5((string) json_encode(collect(['rooms', 'landlord_invitations', 'landlord_members', 'audit_logs'])
        ->map(fn (string $table) => DB::table($table)->orderBy('id')->get())));
}

it('T7: a caretaker changing a rate gets 403, and nothing changes', function (): void {
    [, $caretaker, $room] = t7Account();
    $before = t7State();

    $this->actingAs($caretaker)->patch("/landlord/rooms/{$room->id}/rate", ['monthly_rate' => '1'])->assertForbidden();
    $this->actingAs($caretaker)->withHeaders(['X-Inertia' => 'true'])->patch("/landlord/rooms/{$room->id}/rate", ['monthly_rate' => '1'])->assertForbidden();
    $this->actingAs($caretaker)->patchJson("/landlord/rooms/{$room->id}/rate", ['monthly_rate' => '1'])->assertForbidden();

    expect($room->fresh()->monthly_rate)->toBe(650000)
        ->and(t7State())->toBe($before)
        ->and(auditRows())->toBeEmpty();
});

it('T7: a caretaker creating an invitation gets 403, and nothing changes', function (): void {
    [, $caretaker] = t7Account();
    $before = t7State();

    $this->actingAs($caretaker)->post('/landlord/invitations', ['email' => 'new@example.com'])->assertForbidden();
    $this->actingAs($caretaker)->postJson('/landlord/invitations', ['email' => 'new@example.com', 'role' => 'owner'])->assertForbidden();

    expect(LandlordInvitation::query()->count())->toBe(0)
        ->and(t7State())->toBe($before)
        ->and(auditRows())->toBeEmpty();
});

it('T7: a caretaker is refused before validation, so a bad body is 403 too', function (): void {
    [, $caretaker, $room] = t7Account();

    $this->actingAs($caretaker)->patch("/landlord/rooms/{$room->id}/rate", [])->assertForbidden();
    $this->actingAs($caretaker)->post('/landlord/invitations', [])->assertForbidden();
});

it('T7: the owner changes the rate, with exactly one audit row', function (): void {
    [$owner, , $room] = t7Account();

    $this->actingAs($owner)->patch("/landlord/rooms/{$room->id}/rate", ['monthly_rate' => '7200'])->assertRedirect();

    expect($room->fresh()->monthly_rate)->toBe(720000)
        ->and(auditRows())->toHaveCount(1)
        ->and(auditRows('room.rate_changed'))->toHaveCount(1);
});

it('T7: the owner invites a caretaker, with exactly one audit row', function (): void {
    [$owner] = t7Account();

    $this->actingAs($owner)->post('/landlord/invitations', ['email' => 'new@example.com'])->assertRedirect('/landlord/members');

    expect(LandlordInvitation::query()->count())->toBe(1)
        ->and(auditRows())->toHaveCount(1)
        ->and(auditRows('invitation.created'))->toHaveCount(1);
});

it('T7: a renter gets 403 on both writes too', function (): void {
    [, , $room] = t7Account();
    $renter = User::factory()->renter()->create();

    $this->actingAs($renter)->patch("/landlord/rooms/{$room->id}/rate", ['monthly_rate' => '1'])->assertForbidden();
    $this->actingAs($renter)->post('/landlord/invitations', ['email' => 'new@example.com'])->assertForbidden();

    expect($room->fresh()->monthly_rate)->toBe(650000)->and(auditRows())->toBeEmpty();
});
