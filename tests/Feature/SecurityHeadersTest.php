<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Vite;

it('sends the security headers on web responses', function (string $uri): void {
    $response = $this->get($uri);

    $response->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy');

    $permissions = (string) $response->headers->get('Permissions-Policy');
    foreach (['camera=()', 'microphone=()', 'geolocation=()', 'payment=()'] as $feature) {
        expect($permissions)->toContain($feature);
    }
})->with(['home' => '/', 'health' => '/up', 'not found' => '/does-not-exist']);

it('uses a strict CSP with a per-request nonce that matches the page', function (): void {
    $first = $this->get('/');
    $csp = (string) $first->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain('unsafe-inline')
        ->not->toContain('unsafe-eval');

    preg_match("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $csp, $matches);
    $nonce = $matches[1] ?? null;

    expect($nonce)->not->toBeNull()
        ->and($csp)->toContain("style-src 'self' 'nonce-{$nonce}'")
        ->and($first->getContent())->toContain("<meta property=\"csp-nonce\" nonce=\"{$nonce}\">");

    $second = $this->get('/');
    expect((string) $second->headers->get('Content-Security-Policy'))->not->toContain($nonce);
});

it('sends HSTS only in production', function (): void {
    $this->get('/up')->assertHeaderMissing('Strict-Transport-Security');

    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('allows the Vite dev server only outside production', function (): void {
    $hot = Vite::hotFile();
    $hadHotFile = is_file($hot);
    $original = $hadHotFile ? (string) file_get_contents($hot) : null;
    file_put_contents($hot, 'http://localhost:5183');

    try {
        $dev = (string) $this->get('/up')->headers->get('Content-Security-Policy');
        expect($dev)->toContain('script-src \'self\' \'nonce-')
            ->toContain('http://localhost:5183')
            ->toContain('connect-src \'self\' http://localhost:5183 ws://localhost:5183');

        app()->detectEnvironment(fn (): string => 'production');

        $production = (string) $this->get('/up')->headers->get('Content-Security-Policy');
        expect($production)->not->toContain('localhost:5183')
            ->toContain('upgrade-insecure-requests');
    } finally {
        $hadHotFile ? file_put_contents($hot, (string) $original) : unlink($hot);
    }
});
