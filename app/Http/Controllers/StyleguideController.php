<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders every design token and component (docs/DESIGN_SYSTEM.md). Development
 * only: checked per request rather than at route registration, so a cached
 * route file can't expose it in production.
 */
final class StyleguideController
{
    public function __invoke(): Response
    {
        abort_if(app()->isProduction(), 404);

        return Inertia::render('Dev/Styleguide');
    }
}
