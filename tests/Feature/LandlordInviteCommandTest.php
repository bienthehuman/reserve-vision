<?php

declare(strict_types=1);

use App\Models\LandlordInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

// Slice 4.3: `php artisan landlord:invite {slug} {email}` creates a caretaker invitation and prints its link.
// For an operator when email can't be relied on, and for CI's production run, which has no mailbox to read.

it('creates the invitation in the first owner\'s name and prints a link that works, without sending mail', function (): void {
    Mail::fake();
    $owner = landlordUser();
    $account = $owner->landlordMember()->sole()->landlordAccount;

    expect(Artisan::call('landlord:invite', ['slug' => $account->slug, 'email' => 'Helper@Example.com']))->toBe(0);

    preg_match('#https?://\S+/invitations/([A-Za-z0-9]+)#', Artisan::output(), $match);
    $invitation = LandlordInvitation::query()->sole();

    expect($invitation->email)->toBe('helper@example.com')
        ->and($invitation->landlord_account_id)->toBe($account->id)
        ->and($invitation->invited_by)->toBe($owner->id)
        ->and(hash('sha256', $match[1] ?? ''))->toBe($invitation->token_hash)
        ->and(auditRows('invitation.created')->sole()->actor_id)->toBeNull();
    Mail::assertNothingQueued();

    $this->get("/invitations/{$match[1]}")->assertOk();
});

it('fails with the reason for an unknown account, a bad email, or an email that can\'t be invited', function (): void {
    $owner = landlordUser();
    $slug = $owner->landlordMember()->sole()->landlordAccount->slug;
    $renter = User::factory()->renter()->create();

    $this->artisan('landlord:invite', ['slug' => 'no-such-account', 'email' => 'a@example.com'])->assertExitCode(1)->expectsOutputToContain('No landlord account');
    $this->artisan('landlord:invite', ['slug' => $slug, 'email' => 'not-an-email'])->assertExitCode(1)->expectsOutputToContain('valid email');
    $this->artisan('landlord:invite', ['slug' => $slug, 'email' => $renter->email])->assertExitCode(1)->expectsOutputToContain('renter account');

    expect(LandlordInvitation::query()->count())->toBe(0);
});
