<?php

declare(strict_types=1);

use App\Exceptions\Domain\NoSpaceAvailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('web')->post('/_test/broken-rule', fn () => throw NoSpaceAvailable::forDates());
    Route::middleware('web')->get('/_test/form', fn () => 'form');
});

const BROKEN_RULE_MESSAGE = 'This room has no free space for those dates. Try other dates or another room.';

it('renders as 422 JSON with the message', function (): void {
    $this->postJson('/_test/broken-rule')
        ->assertStatus(422)
        ->assertExactJson(['message' => BROKEN_RULE_MESSAGE]);
});

it('redirects an Inertia request back with the message flashed', function (): void {
    $this->from('/_test/form')
        ->post('/_test/broken-rule', [], ['X-Inertia' => 'true'])
        ->assertStatus(303)
        ->assertRedirect('/_test/form')
        ->assertSessionHas('error', BROKEN_RULE_MESSAGE);
});

it('renders a plain request as a 422 page', function (): void {
    $this->post('/_test/broken-rule')->assertStatus(422);
});

it('is not reported to the log', function (): void {
    Log::spy();

    $this->postJson('/_test/broken-rule')->assertStatus(422);

    Log::shouldNotHaveReceived('error');
});
