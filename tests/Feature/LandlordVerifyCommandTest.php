<?php

declare(strict_types=1);

use App\Models\LandlordAccount;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

// landlord:verify (slice 2.2): the operator's verification tool (docs/ACCESS.md).

it('verifies an account and stamps verified_at', function (): void {
    $account = LandlordAccount::factory()->create(['slug' => 'casa-uno', 'is_verified' => false, 'verified_at' => null]);

    $this->artisan('landlord:verify', ['slug' => 'casa-uno'])
        ->expectsOutputToContain('Verified [casa-uno]')
        ->assertExitCode(0);

    $account->refresh();
    expect($account->is_verified)->toBeTrue()
        ->and($account->verified_at)->not->toBeNull();
});

it('leaves an already verified account alone', function (): void {
    $verifiedAt = now()->subDays(3)->startOfSecond();
    $account = LandlordAccount::factory()->create(['slug' => 'casa-dos', 'is_verified' => true, 'verified_at' => $verifiedAt]);

    $this->artisan('landlord:verify', ['slug' => 'casa-dos'])
        ->expectsOutputToContain('already verified')
        ->assertExitCode(0);

    expect($account->refresh()->verified_at?->equalTo($verifiedAt))->toBeTrue();
});

it('revokes verification and unpublishes every property of that account only', function (): void {
    $account = LandlordAccount::factory()->create(['slug' => 'casa-tres', 'is_verified' => true, 'verified_at' => now()]);
    $other = LandlordAccount::factory()->create(['is_verified' => true, 'verified_at' => now()]);
    Property::factory()->count(2)->published()->create(['landlord_account_id' => $account->id]);
    Property::factory()->create(['landlord_account_id' => $account->id, 'is_published' => false]);
    $othersProperty = Property::factory()->published()->create(['landlord_account_id' => $other->id]);

    $this->artisan('landlord:verify', ['slug' => 'casa-tres', '--revoke' => true])
        ->expectsOutputToContain('Revoked verification of [casa-tres]. Unpublished 2 properties.')
        ->assertExitCode(0);

    $account->refresh();
    expect($account->is_verified)->toBeFalse()
        ->and($account->verified_at)->toBeNull()
        ->and(Property::query()->where('landlord_account_id', $account->id)->where('is_published', true)->count())->toBe(0)
        ->and(Property::query()->where('landlord_account_id', $account->id)->count())->toBe(3)
        ->and($othersProperty->refresh()->is_published)->toBeTrue()
        ->and($other->refresh()->is_verified)->toBeTrue();
});

it('revokes an unverified account without error', function (): void {
    LandlordAccount::factory()->create(['slug' => 'casa-cuatro', 'is_verified' => false, 'verified_at' => null]);

    $this->artisan('landlord:verify', ['slug' => 'casa-cuatro', '--revoke' => true])
        ->expectsOutputToContain('was not verified')
        ->assertExitCode(0);
});

it('rolls the revoke back as a whole if unpublishing fails', function (): void {
    $account = LandlordAccount::factory()->create(['slug' => 'casa-cinco', 'is_verified' => true, 'verified_at' => now()]);
    Property::factory()->published()->create(['landlord_account_id' => $account->id]);

    // Fail right after the unpublish statement runs, so the account update before it must roll back.
    DB::listen(function ($query): void {
        if (str_starts_with($query->sql, 'update `properties`')) {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => $this->artisan('landlord:verify', ['slug' => 'casa-cinco', '--revoke' => true])->run())
        ->toThrow(RuntimeException::class);

    expect($account->refresh()->is_verified)->toBeTrue();
});

it('fails clearly for an unknown slug', function (): void {
    $this->artisan('landlord:verify', ['slug' => 'nope'])
        ->expectsOutputToContain('No landlord account with the slug [nope]')
        ->assertExitCode(1);

    $this->artisan('landlord:verify', ['slug' => 'nope', '--revoke' => true])->assertExitCode(1);
});

it('needs a slug unless listing', function (): void {
    $this->artisan('landlord:verify')
        ->expectsOutputToContain('Give the slug')
        ->assertExitCode(1);
});

it('lists the unverified accounts with their property counts', function (): void {
    $pending = LandlordAccount::factory()->create(['slug' => 'pending-one', 'is_verified' => false, 'verified_at' => null]);
    Property::factory()->count(2)->create(['landlord_account_id' => $pending->id]);
    LandlordAccount::factory()->create(['slug' => 'already-ok', 'is_verified' => true, 'verified_at' => now()]);

    $this->artisan('landlord:verify', ['--list' => true])
        ->expectsOutputToContain('pending-one')
        ->doesntExpectOutputToContain('already-ok')
        ->assertExitCode(0);
});

it('says so when nothing is waiting for verification', function (): void {
    LandlordAccount::query()->update(['is_verified' => true]);

    $this->artisan('landlord:verify', ['--list' => true])
        ->expectsOutputToContain('Every landlord account is verified')
        ->assertExitCode(0);
});

it('rejects --list combined with a slug or --revoke', function (): void {
    $this->artisan('landlord:verify', ['slug' => 'x', '--list' => true])->assertExitCode(1);
    $this->artisan('landlord:verify', ['--revoke' => true, '--list' => true])->assertExitCode(1);
});
