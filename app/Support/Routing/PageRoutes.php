<?php

declare(strict_types=1);

namespace App\Support\Routing;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * Every GET route of the app, as data (slice 7.2): the list the accessibility sweep (e2e/a11y-sweep.spec.ts)
 * is built from. The sweep must decide for each one whether it renders a page (and which actors see it)
 * or isn't one (a file, a redirect, JSON), so a new page can't skip it. Checked in as
 * e2e/fixtures/page-routes.json; tests/Feature/Docs/PageRoutesFixtureTest.php fails when it is stale.
 */
final class PageRoutes
{
    /** Where the fixture lives, relative to the project root. */
    public const FIXTURE = 'e2e/fixtures/page-routes.json';

    /**
     * @return list<array{key: string, uri: string, access: string, parameters: list<string>}>
     */
    public static function all(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            // The Inertia devtools exist only when the app is in debug mode: not part of the product, and not in the tests' environment.
            if (! in_array('GET', $route->methods(), true) || str_starts_with($route->uri(), '_inertia/')) {
                continue;
            }

            $routes[] = [
                'key' => $route->getName() ?? '/'.ltrim($route->uri(), '/'),
                'uri' => '/'.ltrim($route->uri(), '/'),
                'access' => self::access($route),
                'parameters' => array_values(array_filter($route->parameterNames(), is_string(...))),
            ];
        }

        usort($routes, fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return $routes;
    }

    public static function json(): string
    {
        return json_encode(self::all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /** Who the route's middleware lets in: `landlord`, `renter`, `auth` (any logged-in user), `guest`, `signed` or `public`. */
    private static function access(LaravelRoute $route): string
    {
        $middleware = array_map(fn (mixed $name): string => is_string($name) ? $name : '', $route->gatherMiddleware());

        foreach (['landlord', 'renter', 'guest'] as $name) {
            if (in_array($name, $middleware, true)) {
                return $name;
            }
        }

        foreach ($middleware as $name) {
            if (str_starts_with($name, 'signed')) {
                return 'signed';
            }
        }

        return in_array('auth', $middleware, true) ? 'auth' : 'public';
    }
}
