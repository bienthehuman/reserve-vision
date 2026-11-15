<?php

declare(strict_types=1);

use App\Mail\Auth\VerifyEmailMail;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

function verificationUrl(User $user, ?string $hash = null): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => $hash ?? sha1($user->email),
    ]);
}

it('redirects an unverified landlord from landlord pages to the "check your email" page', function (): void {
    $this->actingAs(landlordUser(verifiedEmail: false))->get('/landlord')->assertRedirect('/email/verify');
});

it('server-renders the "check your email" page with the address', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'maria@example.com']);

    $response = $this->actingAs($user)->get('/email/verify');

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Auth/VerifyEmail')
        ->where('email', 'maria@example.com'));
    expect($response->getContent())->toMatch('/<h1[^>]*>Confirm your email<\/h1>/');
});

it('sends a verified user on from the "check your email" page', function (): void {
    $this->actingAs(User::factory()->create())->get('/email/verify')->assertRedirect();
});

it('verifies the email from the signed link and sends the user home', function (bool $landlord): void {
    Event::fake([Verified::class]);
    $user = $landlord ? landlordUser(verifiedEmail: false) : User::factory()->renter()->unverified()->create();

    $this->actingAs($user)->get(verificationUrl($user))
        ->assertRedirect($landlord ? '/landlord' : '/')
        ->assertSessionHas('status', 'Your email is verified. Welcome to ReserveVision!');

    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
})->with(['renter' => false, 'landlord' => true]);

it('rejects a link with the wrong hash or a broken signature', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(verificationUrl($user, sha1('someone-else@example.com')))->assertForbidden();
    $this->actingAs($user)->get(verificationUrl($user).'tampered')->assertForbidden();

    expect($user->fresh()?->hasVerifiedEmail())->toBeFalse();
});

it('sends the link again on request', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->from('/email/verify')->post('/email/verification-notification')
        ->assertRedirect('/email/verify')
        ->assertSessionHas('status', 'verification-link-sent');

    Notification::assertSentTo($user, VerifyEmailNotification::class);

    // The page shows the sentence, not the key.
    $this->actingAs($user)->get('/email/verify')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('flash.status', 'We\'ve sent a new link. Check your inbox (and the spam folder).'));
});

it('sends a queued, themed Markdown mail with the signed link', function (): void {
    $user = User::factory()->unverified()->create(['first_name' => 'Ana']);
    $notification = new VerifyEmailNotification;

    $mail = $notification->toMail($user);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($mail)->toBeInstanceOf(VerifyEmailMail::class);

    $mail->assertTo($user->email)
        ->assertHasSubject('Confirm your email for ReserveVision')
        ->assertSeeInHtml('Hi Ana, confirm your email')
        ->assertSeeInHtml('/email/verify/'.$user->id.'/'.sha1($user->email), false)
        ->assertSeeInHtml('signature=', false)
        ->assertSeeInHtml('#5B3E96');
});
