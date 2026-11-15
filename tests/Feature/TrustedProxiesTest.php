<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Production runs behind Caddy and Codespaces behind GitHub's port forwarding (slice 1.3).

it('builds URLs from the X-Forwarded headers of the proxy', function (): void {
    Route::get('/__proxy-probe', fn () => url('/build/app.js'));

    $this->get('/__proxy-probe', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'reservevision.example',
        'X-Forwarded-Port' => '443',
    ])->assertOk()->assertContent('https://reservevision.example/build/app.js');
});
