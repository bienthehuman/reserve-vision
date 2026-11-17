<?php

declare(strict_types=1);

use App\Support\Routing\PageRoutes;

// The accessibility sweep (e2e/a11y-sweep.spec.ts) reads its page list from e2e/fixtures/page-routes.json and fails
// for any GET route it has no entry for, so a new page can't skip axe. This test keeps the file in step with the
// route list: add a route, run `docker compose exec app php artisan app:page-routes --write`, then map it in the spec.

it('has an up-to-date page route fixture for the accessibility sweep', function (): void {
    $file = dirname(__DIR__, 3).'/'.PageRoutes::FIXTURE;

    expect(is_file($file))->toBeTrue('missing '.PageRoutes::FIXTURE.': run php artisan app:page-routes --write');
    expect(file_get_contents($file))->toBe(
        PageRoutes::json(),
        'e2e/fixtures/page-routes.json is stale: run `docker compose exec app php artisan app:page-routes --write`, then map the new route in e2e/a11y-sweep.spec.ts',
    );
});

it('classifies who may open a route from its middleware', function (): void {
    $access = collect(PageRoutes::all())->pluck('access', 'key');

    expect($access['home'])->toBe('public')
        ->and($access['login'])->toBe('guest')
        ->and($access['reservations.show'])->toBe('renter')
        ->and($access['landlord.dashboard'])->toBe('landlord')
        ->and($access['verification.notice'])->toBe('auth')
        ->and($access['proofs.file'])->toBe('signed');
});
