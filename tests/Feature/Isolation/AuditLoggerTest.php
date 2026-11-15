<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Enums\RoomType;
use App\Exceptions\Isolation\CrossAccountWrite;
use App\Models\AuditLog;
use App\Models\LandlordAccount;
use App\Models\LandlordInvitation;
use App\Models\LandlordMember;
use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// AuditLogger (docs/CONVENTIONS.md "Audit log"): one row per change, only the
// changed fields, never a secret, inside the caller's transaction.

function auditLogger(): AuditLogger
{
    return app(AuditLogger::class);
}

it('records the landlord actor, their account and only the fields that changed', function (): void {
    $user = landlordUser(role: LandlordRole::Caretaker);
    $account = LandlordMember::query()->where('user_id', $user->id)->value('landlord_account_id');
    $room = Room::factory()->for(Property::factory()->state(['landlord_account_id' => $account]))->create(['monthly_rate' => 500000]);

    $this->actingAs($user);
    $entry = auditLogger()->record(
        'room.rate_changed',
        $room,
        ['monthly_rate' => 500000, 'name' => $room->name, 'room_type' => RoomType::Private],
        ['monthly_rate' => 550000, 'name' => $room->name, 'room_type' => RoomType::Private],
    );

    expect($entry)->not->toBeNull();
    $row = AuditLog::query()->findOrFail($entry?->id);

    expect($row->actor_id)->toBe($user->id)
        ->and($row->landlord_account_id)->toBe($account)
        ->and($row->action)->toBe('room.rate_changed')
        ->and($row->subject_type)->toBe('room')
        ->and($row->subject_id)->toBe($room->id)
        ->and($row->before)->toBe(['monthly_rate' => 500000])
        ->and($row->after)->toBe(['monthly_rate' => 550000]);
});

it('stores enums by value and dates as ISO 8601, and keeps fields that appear on one side only', function (): void {
    $account = LandlordAccount::factory()->create();

    $entry = auditLogger()->record(
        'account.verified',
        $account,
        ['verified_at' => null, 'role' => LandlordRole::Caretaker],
        ['verified_at' => CarbonImmutable::parse('2027-01-02 03:04:05', 'UTC'), 'role' => LandlordRole::Owner, 'note' => 'new'],
    );

    expect($entry?->before)->toBe(['verified_at' => null, 'role' => 'caretaker'])
        ->and($entry?->after)->toBe(['verified_at' => '2027-01-02T03:04:05+00:00', 'role' => 'owner', 'note' => 'new']);
});

it('records a null actor and the subject\'s account for the operator and the system', function (): void {
    $account = LandlordAccount::factory()->create();
    $invitation = LandlordInvitation::factory()->for($account)->create();

    $forAccount = auditLogger()->record('account.verified', $account, ['is_verified' => false], ['is_verified' => true]);
    $forInvitation = auditLogger()->record('invitation.revoked', $invitation, ['revoked_at' => null], ['revoked_at' => '2027-01-01']);

    expect($forAccount?->actor_id)->toBeNull()
        ->and($forAccount?->landlord_account_id)->toBe($account->id)
        ->and($forAccount?->subject_type)->toBe('landlord_account')
        ->and($forInvitation?->actor_id)->toBeNull()
        ->and($forInvitation?->landlord_account_id)->toBe($account->id)
        ->and($forInvitation?->subject_type)->toBe('landlord_invitation');
});

it('never stores a secret or a token, only that it changed', function (string $field): void {
    $account = LandlordAccount::factory()->create();
    $secretBefore = 'old-secret-'.$field;
    $secretAfter = 'new-secret-'.$field;

    $entry = auditLogger()->record('account.secret_changed', $account, [$field => $secretBefore], [$field => $secretAfter]);

    $raw = (array) DB::table('audit_logs')->where('id', $entry?->id)->first(['before', 'after']);

    expect(json_encode($raw))->not->toContain('secret-')
        ->and($entry?->before)->toBe([$field => AuditLogger::REDACTED])
        ->and($entry?->after)->toBe([$field => AuditLogger::REDACTED]);
})->with(['password', 'remember_token', 'token_hash', 'token', 'Password']);

it('records nothing when nothing changed', function (): void {
    $account = LandlordAccount::factory()->create();

    expect(auditLogger()->record('account.verified', $account, ['is_verified' => true], ['is_verified' => true]))->toBeNull()
        ->and(AuditLog::query()->where('landlord_account_id', $account->id)->count())->toBe(0);
});

it('writes inside the caller\'s transaction: a rolled-back action leaves no row', function (): void {
    $account = LandlordAccount::factory()->create();

    expect(fn () => DB::transaction(function () use ($account): void {
        auditLogger()->record('account.verified', $account, ['is_verified' => false], ['is_verified' => true]);

        throw new RuntimeException('the action failed after recording');
    }))->toThrow(RuntimeException::class);

    expect(AuditLog::query()->where('landlord_account_id', $account->id)->count())->toBe(0);
});

it('refuses an action name that is not subject.verb in snake_case', function (string $action): void {
    expect(fn () => auditLogger()->record($action, LandlordAccount::factory()->create(), ['a' => 1], ['a' => 2]))
        ->toThrow(InvalidArgumentException::class, 'subject.verb');
})->with(['RateChanged', 'room', 'room.rateChanged', 'room.rate-changed', 'Room.rate_changed', 'room.rate_changed.again']);

it('refuses to record another account\'s record for a landlord user', function (): void {
    $theirs = Room::factory()->create();
    $this->actingAs(landlordUser());

    expect(fn () => auditLogger()->record('room.rate_changed', $theirs, ['monthly_rate' => 1], ['monthly_rate' => 2]))
        ->toThrow(CrossAccountWrite::class);
});

it('records a renter\'s change against the subject\'s account', function (): void {
    $renter = User::factory()->renter()->create();
    $room = Room::factory()->create();
    $this->actingAs($renter);

    $entry = auditLogger()->record('room.viewed', $room, ['a' => 1], ['a' => 2]);

    expect($entry?->actor_id)->toBe($renter->id)
        ->and($entry?->landlord_account_id)->toBe($room->landlord_account_id);
});
