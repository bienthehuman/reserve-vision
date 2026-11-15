<?php

declare(strict_types=1);

use App\Http\Middleware\ExposeRequestTiming;
use App\Models\User;
use App\Support\Concurrency\Probe;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

/*
| The concurrency harness's test-only switch (config/concurrency.php, ADR 0001):
| CONCURRENCY_PROBE adds server timing headers to reserve responses. It can't
| be switched on in production.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

function reserveAsNewRenter(): TestResponse
{
    return test()->actingAs(User::factory()->create())
        ->postJson('/rooms/'.reservableRoom()->id.'/reservations', ['move_in' => '2027-04-01', 'months' => 6])
        ->assertCreated();
}

it('is off by default: no timing headers', function (): void {
    expect(Probe::timingEnabled(app()))->toBeFalse();

    reserveAsNewRenter()
        ->assertHeaderMissing('X-Request-Start')
        ->assertHeaderMissing('X-Request-End');
});

it('adds the server timing headers outside production when switched on', function (): void {
    config(['concurrency.probe' => true]);

    $response = reserveAsNewRenter();

    $start = (float) $response->headers->get('X-Request-Start');
    $end = (float) $response->headers->get('X-Request-End');
    expect($start)->toBeGreaterThan(0.0)->and($end)->toBeGreaterThanOrEqual($start);
});

it('is ignored in production', function (): void {
    config(['concurrency.probe' => true]);
    app()->detectEnvironment(fn (): string => 'production');

    $response = app(ExposeRequestTiming::class)->handle(Request::create('/'), fn (): Response => new Response('ok'));

    expect(Probe::timingEnabled(app()))->toBeFalse()
        ->and($response->headers->has('X-Request-Start'))->toBeFalse()
        ->and($response->headers->has('X-Request-End'))->toBeFalse();
});

it('stays off when a production app boots with CONCURRENCY_PROBE=true', function (): void {
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo json_encode([
            'env' => $app->environment(),
            'probe_config' => config('concurrency.probe'),
            'timing' => App\Support\Concurrency\Probe::timingEnabled($app),
        ]);
        PHP;

    $result = Process::path(base_path())
        ->env(['APP_ENV' => 'production', 'CONCURRENCY_PROBE' => 'true', 'APP_CONFIG_CACHE' => '/nonexistent/config.php'])
        ->run(['php', '-r', $script]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(json_decode($result->output(), true))->toBe(['env' => 'production', 'probe_config' => true, 'timing' => false]);
});
