<?php

declare(strict_types=1);

use App\Models\User;

it('logs out, invalidates the session and goes home', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/');
    session()->put('something', 'from this session');
    $before = session()->getId();
    $token = session()->token();

    $this->post('/logout')->assertRedirect('/');

    $this->assertGuest();
    expect(session()->getId())->not->toBe($before)
        ->and(session()->token())->not->toBe($token)
        ->and(session()->has('something'))->toBeFalse();
});

it('needs a logged-in user', function (): void {
    $this->post('/logout')->assertRedirect('/login');
});
