<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Concurrency\Probe;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-only (CONCURRENCY_PROBE, never in production): when the server started
 * and finished handling the request, by its own clock. The concurrency harness
 * uses them to prove the racing requests were in the server at the same time;
 * client-side times can't, since a server that queues requests still accepts
 * every connection at once.
 */
final readonly class ExposeRequestTiming
{
    public function __construct(private Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Probe::timingEnabled($this->app)) {
            /** @var Response */
            return $next($request);
        }

        // Set by public/index.php as the first statement, before the framework boots.
        $start = defined('LARAVEL_START') ? (float) LARAVEL_START : microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-Start', sprintf('%.6f', $start));
        $response->headers->set('X-Request-End', sprintf('%.6f', microtime(true)));

        return $response;
    }
}
