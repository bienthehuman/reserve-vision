<?php

declare(strict_types=1);

namespace App\Support\Concurrency;

use Illuminate\Contracts\Foundation\Application;

/**
 * Reads the concurrency harness's test-only switch (config/concurrency.php).
 * Production always gets it switched off, whatever the environment says.
 */
final class Probe
{
    public static function timingEnabled(Application $app): bool
    {
        return ! $app->isProduction() && config('concurrency.probe') === true;
    }
}
