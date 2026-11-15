<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;

// The lockout message counts seconds; a frozen clock keeps it at exactly 60.
beforeEach(fn () => $this->freezeTime());

it('locks login after 5 failed tries a minute for that email and IP', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'That email and password don\'t match an account. Check them and try again.']);
    }

    // Even the right password is refused now …
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Too many tries. Wait 60 seconds, then try again.']);
    $this->assertGuest();

    // … but another email from the same IP is not locked out.
    $other = User::factory()->create();
    $this->post('/login', ['email' => $other->email, 'password' => 'password'])->assertRedirect('/');
    $this->assertAuthenticatedAs($other);
});

it('lets login through again after a minute', function (): void {
    $user = User::factory()->create();
    foreach (range(1, 6) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
    }

    $this->travel(61)->seconds();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');
});

it('allows 3 sign-ups a minute per IP', function (): void {
    Notification::fake();

    foreach (range(1, 3) as $n) {
        $this->post('/register', registrationForm(['email' => "person{$n}@example.com"]))->assertRedirect('/email/verify');
        $this->post('/logout');
    }

    $this->from('/register')->post('/register', registrationForm(['email' => 'person4@example.com']))
        ->assertStatus(303)
        ->assertRedirect('/register')
        ->assertSessionHasErrors(['email' => 'Too many tries. Wait 60 seconds, then try again.']);

    expect(User::query()->where('email', 'person4@example.com')->exists())->toBeFalse();
});

it('allows 3 reset-link requests a minute per IP', function (): void {
    Notification::fake();

    foreach (range(1, 3) as $n) {
        $this->post('/forgot-password', ['email' => "person{$n}@example.com"])->assertSessionHasNoErrors();
    }

    $this->from('/forgot-password')->post('/forgot-password', ['email' => 'person4@example.com'])
        ->assertStatus(303)
        ->assertSessionHasErrors(['email' => 'Too many tries. Wait 60 seconds, then try again.']);
});
