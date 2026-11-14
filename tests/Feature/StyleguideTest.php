<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;

it('renders the styleguide outside production', function (): void {
    $this->get('/styleguide')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Dev/Styleguide'));
});

it('is a 404 in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/styleguide')->assertNotFound();
});
