<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;

it('server-renders the home page, so it is complete HTML without JavaScript', function (): void {
    $response = $this->get('/');

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Public/Home')
            ->where('appName', 'ReserveVision'));

    $html = $response->getContent();

    // The pitch is in the raw HTML …
    expect($html)->toContain('Reserve apartments and boarding-house beds online, with no double bookings.');
    // … inside markup that only SSR produces (without SSR the body is an empty <div id="app">).
    expect($html)->toMatch('/<h1[^>]*id="hero-title"[^>]*>ReserveVision<\/h1>/')
        ->toMatch('/<form role="search"[^>]*action="\/"[^>]*method="get"/')
        ->toMatch('/<title[^>]*>Find a room or a bed · ReserveVision<\/title>/');
});

it('serves the health route', function (): void {
    $this->get('/up')->assertOk();
});
