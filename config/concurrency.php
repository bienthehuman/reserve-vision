<?php

declare(strict_types=1);

/*
| Test-only switch for the concurrency harness (tests/Concurrency, ADR 0001).
| Ignored when APP_ENV=production, whatever the environment says
| (App\Support\Concurrency\Probe; tests/Feature/Concurrency/ProbeSwitchTest.php).
*/
return [

    // Adds X-Request-Start / X-Request-End (server clock, epoch seconds with
    // microseconds) to reserve responses, so the harness can prove the server
    // really handled the racing requests at the same time.
    'probe' => (bool) env('CONCURRENCY_PROBE', false),

];
