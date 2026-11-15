<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response (docs/ARCHITECTURE.md, "Production constraints").
 *
 * The CSP uses a per-request nonce. Vite puts it on the tags it emits, the root
 * view exposes it in <meta property="csp-nonce"> for Inertia and the Vite client,
 * and SSR output contains no executable inline script, so nothing else needs it.
 *
 * Dev-only relaxation: while the Vite dev server is running (public/hot exists)
 * outside production, its origin is allowed for scripts, styles, fonts, images
 * and the HMR websocket. Nothing else is relaxed, and never in production.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce, $request->isSecure()));
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', implode(', ', [
            'accelerometer=()',
            'autoplay=()',
            'camera=()',
            'display-capture=()',
            'geolocation=()',
            'gyroscope=()',
            'magnetometer=()',
            'microphone=()',
            'payment=()',
            'usb=()',
        ]));

        if (app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce, bool $secure): string
    {
        $vite = $this->viteDevOrigin();
        $viteSocket = $vite !== null ? (string) preg_replace('/^http/', 'ws', $vite) : null;

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", $vite],
            'style-src' => ["'self'", "'nonce-{$nonce}'", $vite],
            'img-src' => ["'self'", 'data:', $vite],
            'font-src' => ["'self'", $vite],
            'connect-src' => ["'self'", $vite, $viteSocket],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        $policy = [];
        foreach ($directives as $name => $sources) {
            $policy[] = $name.' '.implode(' ', array_filter($sources));
        }

        // HTTPS requests only (always the case behind Caddy with TLS). On a page served over
        // plain HTTP (CI's production stack) it would upgrade Inertia's same-origin
        // redirect follow-ups to https://, which isn't there, so every form post would
        // stall after a successful login (slice 2.4).
        if (app()->isProduction() && $secure) {
            $policy[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $policy);
    }

    /**
     * The Vite dev server origin, e.g. http://localhost:5183, when it is running
     * and we're not in production; otherwise null.
     */
    private function viteDevOrigin(): ?string
    {
        if (app()->isProduction() || ! Vite::isRunningHot()) {
            return null;
        }

        $url = trim((string) @file_get_contents(Vite::hotFile()));
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
