<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Models\LandlordInvitation;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Models\ViewingRequest;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
| T6 (BUILD_PLAN.md): landlord A requests landlord B's property, room, reservation
| or proof by id, on EVERY landlord route; renter A requests renter B's reservation,
| on every renter route. Expected: 404 and no data.
|
| The routes are not listed here: the test walks Route::getRoutes() and picks
| every route behind the `landlord` middleware and every one behind `renter`, so a
| route added by a later slice is covered the moment it exists. What a route's
| parameters mean is explicit, in T6_PARAMETERS below. A parameter name that is
| not in it FAILS the test, and so does a non-GET route with no body in T6_BODIES.
| That is the point: a new route can't silently skip T6.
|
| HOW TO EXTEND (every slice that adds a record route behind `landlord` or `renter`):
|  1. New route parameter name, e.g. `{proof}`: add it to T6_PARAMETERS with, for each
|     context (`landlord`, `renter`) that has such a route, a `foreign` record (the
|     other account's, or the other renter's: must be 404) and an `own` record (the
|     actor's: the positive control, proving the URL and body are valid, so a 404 can't be
|     a typo). Both are built from the world in t6World(); add rows to it if needed.
|  2. New POST/PUT/PATCH/DELETE route: add a valid-looking body to T6_BODIES by route name.
|  3. New data that must not leak: add its identifying text to t6Secrets().
|
| Each route is called three times over: as owner A, as caretaker A (still 404, never
| 403) and, for renter routes, as renter A. Each call must leave every table byte for
| byte unchanged, audit_logs included.
*/

/** The tables a request must not change. */
const T6_TABLES = [
    'users', 'landlord_accounts', 'landlord_members', 'landlord_invitations', 'properties', 'rooms', 'spaces',
    'reservations', 'payment_proofs', 'property_photos', 'viewing_requests', 'audit_logs',
];

/**
 * Route parameter => per context, a closure from the world to the record.
 * `foreign`: belongs to account B / renter B. `own`: belongs to account A / renter A.
 *
 * @var array<string, array<string, array{foreign: Closure, own: Closure}>>
 */
const T6_PARAMETERS = [
    'reservation' => [
        'landlord' => ['foreign' => 'reservationB', 'own' => 'reservationA'],
        'renter' => ['foreign' => 'reservationB', 'own' => 'reservationOfRenterA'],
    ],
    'property' => [
        'landlord' => ['foreign' => 'propertyB', 'own' => 'propertyA'],
    ],
    // On a renter route a room belongs to nobody: "foreign" is a room renters can't reserve (unpublished), "own" a listed one.
    'room' => [
        'landlord' => ['foreign' => 'roomB', 'own' => 'roomA'],
        'renter' => ['foreign' => 'unlistedRoomB', 'own' => 'listedRoomA'],
    ],
    'space' => [
        'landlord' => ['foreign' => 'spaceB', 'own' => 'spaceA'],
    ],
    'proof' => [
        'landlord' => ['foreign' => 'proofB', 'own' => 'proofA'],
        'renter' => ['foreign' => 'proofB', 'own' => 'proofOfRenterA'],
    ],
    'photo' => [
        'landlord' => ['foreign' => 'photoB', 'own' => 'photoA'],
    ],
    'viewing' => [
        'landlord' => ['foreign' => 'viewingB', 'own' => 'viewingA'],
        'renter' => ['foreign' => 'viewingB', 'own' => 'viewingOfRenterA'],
    ],
    'invitation' => [
        'landlord' => ['foreign' => 'invitationB', 'own' => 'invitationA'],
    ],
    'member' => [
        'landlord' => ['foreign' => 'memberB', 'own' => 'memberA'],
    ],
];

/**
 * A valid-looking body per non-GET route name. A route that is not here fails the test.
 *
 * @var array<string, Closure>
 */
const T6_BODIES = [
    'landlord.properties.update' => 'propertyBody',
    'landlord.properties.archive' => [],
    'landlord.properties.unarchive' => [],
    'landlord.properties.rooms.store' => 'roomBody',
    'landlord.rooms.update' => 'roomBody',
    'landlord.rooms.archive' => [],
    'landlord.rooms.unarchive' => [],
    'landlord.rooms.spaces.store' => [],
    'landlord.spaces.update' => ['label' => 'Window bed'],
    'landlord.spaces.archive' => [],
    'landlord.spaces.unarchive' => [],
    'rooms.reservations.store' => 'reservationWindow',
];

/**
 * Routes whose control (the same call on the actor's OWN record, as the owner) is a business-rule
 * refusal, not a success. The world's own property, room and space carry a blocking reservation
 * (proof uploaded), so archiving them is refused with 422: the control still proves the URL and body
 * are valid and that the 404 on B's record was the isolation's. The archive actions' own tests cover the success.
 */
const T6_OWN_REFUSED = [
    'landlord.properties.archive' => 422,
    'landlord.rooms.archive' => 422,
    'landlord.spaces.archive' => 422,
];

/** @return array<string, mixed> */
function t6Body(string $routeName): array
{
    $body = T6_BODIES[$routeName];

    return is_array($body) ? $body : $body();
}

/** A valid edit of the world's own property (published, so its city stays as it is). */
function propertyBody(): array
{
    return ['kind' => 'boarding_house', 'name' => 'Alpha Home', 'city' => 'cebu-city', 'address' => '9 Narra St', 'gender_policy' => 'any', 'amenities' => []];
}

/** A valid room form. The world's own room is shared, so `room_type` stays; `beds` is only read for a new shared room. */
function roomBody(): array
{
    return [
        'name' => 'Room Z', 'room_type' => 'shared', 'beds' => 2, 'monthly_rate' => '3000', 'reservation_fee' => '500',
        'deposit_months' => 1, 'advance_months' => 1,
    ];
}

function reservationWindow(): array
{
    return ['move_in' => BusinessDate::today()->addDays(14)->toDateString(), 'months' => 6];
}

/**
 * Two landlord accounts and what belongs to them, and two renters. Everything
 * of B carries a distinctive text (t6Secrets) that must never appear in a response to A.
 * Created before any actingAs(): factories are isolated too (CLAUDE.md, quirks in 3.1).
 *
 * @return array<string, mixed>
 */
function t6World(): array
{
    $w = [];

    // Account A and renter A.
    $w['ownerA'] = landlordUser();
    $a = accountIdOf($w['ownerA']);
    $w['caretakerA'] = landlordUserOf($a, LandlordRole::Caretaker);
    $w['renterA'] = User::factory()->renter()->create(['first_name' => 'Aida', 'last_name' => 'Alvarez']);
    $w['memberA'] = $w['caretakerA']->landlordMember()->sole();
    $w['propertyA'] = Property::factory()->published()->create(['landlord_account_id' => $a, 'name' => 'Alpha Home', 'city' => 'Cebu City', 'city_slug' => 'cebu-city']);
    // Shared, so the space routes have a bed to add, rename and archive; the reservation below blocks archiving it.
    $w['roomA'] = Room::factory()->shared(beds: 2)->for($w['propertyA'])->create();
    // In a property of its own: renter A already holds a bed in Alpha Home, and a second one in the same property is T8's rule.
    $w['listedRoomA'] = Room::factory()->for(Property::factory()->published()->create(['landlord_account_id' => $a, 'name' => 'Alpha Annex']))->create();
    $w['spaceA'] = $w['roomA']->spaces()->orderBy('id')->firstOrFail();
    $w['reservationA'] = Reservation::factory()->for($w['spaceA'], 'space')->for(User::factory()->renter()->create(), 'renter')->proofUploaded()->create();
    $w['proofA'] = PaymentProof::query()->where('reservation_id', $w['reservationA']->id)->sole();
    $w['photoA'] = PropertyPhoto::factory()->for($w['propertyA'])->create();
    $w['invitationA'] = LandlordInvitation::factory()->create(['landlord_account_id' => $a, 'invited_by' => $w['ownerA']->id]);
    $w['viewingA'] = ViewingRequest::factory()->for($w['propertyA'])->for(User::factory()->renter()->create(), 'renter')->create();

    // Renter A's own things, in account A.
    $other = Room::factory()->for($w['propertyA'])->create();
    $w['reservationOfRenterA'] = Reservation::factory()->for($other->spaces()->sole(), 'space')->for($w['renterA'], 'renter')->proofUploaded()->create();
    $w['proofOfRenterA'] = PaymentProof::query()->where('reservation_id', $w['reservationOfRenterA']->id)->sole();
    $w['viewingOfRenterA'] = ViewingRequest::factory()->for($w['propertyA'])->for($w['renterA'], 'renter')->create();

    // Account B, renter B, and everything of theirs that must stay hidden.
    $w['ownerB'] = landlordUser();
    $b = accountIdOf($w['ownerB']);
    $w['caretakerB'] = landlordUserOf($b, LandlordRole::Caretaker);
    $w['renterB'] = User::factory()->renter()->create([
        'first_name' => 'Zelda', 'last_name' => 'Quimbyfoss', 'email' => 'zelda.quimbyfoss@renter-b.test', 'phone' => '09189998877',
    ]);
    $w['memberB'] = $w['caretakerB']->landlordMember()->sole();
    $w['propertyB'] = Property::factory()->published()->create(['landlord_account_id' => $b, 'name' => 'Bravo Hidden Residences']);
    $w['roomB'] = Room::factory()->for($w['propertyB'])->create(['name' => 'Bravo Secret Room']);
    $w['spaceB'] = $w['roomB']->spaces()->sole();
    $w['reservationB'] = Reservation::factory()->for($w['spaceB'], 'space')->for($w['renterB'], 'renter')->proofUploaded()->create();
    $w['proofB'] = PaymentProof::query()->where('reservation_id', $w['reservationB']->id)->sole();
    $w['proofB']->forceFill(['reference_no' => 'BRAVO-REF-7731'])->save();
    $w['photoB'] = PropertyPhoto::factory()->for($w['propertyB'])->create();
    $w['invitationB'] = LandlordInvitation::factory()->create(['landlord_account_id' => $b, 'email' => 'bravo.invitee@example.com', 'invited_by' => $w['ownerB']->id]);
    $w['viewingB'] = ViewingRequest::factory()->for($w['propertyB'])->for($w['renterB'], 'renter')->create();
    $w['unlistedRoomB'] = Room::factory()->for(Property::factory()->create(['landlord_account_id' => $b, 'name' => 'Bravo Unlisted Annex']))->create();

    $w['secrets'] = t6Secrets($w);

    return $w;
}

/**
 * Text that identifies B's data. None of it may be in a response to A.
 *
 * @param  array<string, mixed>  $w
 * @return list<string>
 */
function t6Secrets(array $w): array
{
    return [
        'Zelda', 'Quimbyfoss', 'zelda.quimbyfoss@renter-b.test', '09189998877',
        'Bravo Hidden Residences', 'Bravo Secret Room', 'Bravo Unlisted Annex', 'BRAVO-REF-7731', 'bravo.invitee@example.com',
        $w['ownerB']->full_name, $w['caretakerB']->full_name, $w['ownerB']->email,
    ];
}

/** One hash per table: any inserted, changed or deleted row changes it. */
function t6Snapshot(): array
{
    return collect(T6_TABLES)->mapWithKeys(fn (string $table): array => [
        $table => md5((string) json_encode(DB::table($table)->orderBy('id')->get())),
    ])->all();
}

/**
 * Every route behind the middleware (`landlord` or `renter`), with what T6 needs.
 * Fails on a parameter or body nobody has mapped.
 *
 * @return list<array{route: RoutingRoute, name: string, method: string, parameters: list<string>}>
 */
function t6Routes(string $middleware): array
{
    $routes = [];
    $problems = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! in_array($middleware, $route->gatherMiddleware(), true)) {
            continue;
        }

        $name = $route->getName() ?? $route->uri();
        $method = $route->methods()[0];
        $parameters = $route->parameterNames();

        foreach ($parameters as $parameter) {
            if (! isset(T6_PARAMETERS[$parameter][$middleware])) {
                $problems[] = "Route [{$name}] has the parameter {{$parameter}} behind `{$middleware}`, which T6_PARAMETERS has no `{$middleware}` entry for. "
                    .'Extend T6_PARAMETERS in tests/Feature/Isolation/T6CrossAccessTest.php (see "HOW TO EXTEND" at the top): a route can\'t skip T6.';
            }
        }

        if ($parameters !== [] && $method !== 'GET' && ! isset(T6_BODIES[$name])) {
            $problems[] = "Route [{$name}] ({$method}) has no entry in T6_BODIES: add a valid-looking body, so T6 proves the 404 is the isolation and not a validation error.";
        }

        $routes[] = ['route' => $route, 'name' => $name, 'method' => $method, 'parameters' => $parameters];
    }

    expect($problems)->toBeEmpty(implode("\n", $problems));

    return $routes;
}

/** The URL of a route with each parameter filled from `$side` ('foreign' or 'own'). */
function t6Url(array $routeInfo, array $w, string $context, string $side): string
{
    $values = [];

    foreach ($routeInfo['parameters'] as $parameter) {
        $model = $w[T6_PARAMETERS[$parameter][$context][$side]];
        assert($model instanceof Model);
        $values[$parameter] = $model->getKey();
    }

    return route($routeInfo['route']->getName() ?? '', $values, absolute: false);
}

/**
 * The checks for one actor on every route of a context.
 *
 * @param  list<array{route: RoutingRoute, name: string, method: string, parameters: list<string>}>  $routes
 */
function t6Run(mixed $test, array $routes, array $w, string $context, User $actor, bool $mayAct): array
{
    $covered = [];

    foreach ($routes as $info) {
        if ($info['parameters'] === []) {
            continue; // nothing to point at another account's record: lists and creates are the scope's (ScopeTest) and T7's
        }

        $label = "{$info['method']} ".t6Url($info, $w, $context, 'foreign')." ({$info['name']}) as {$actor->email}";
        $body = $info['method'] === 'GET' ? [] : t6Body($info['name']);
        $before = t6Snapshot();

        $response = $test->actingAs($actor)->call($info['method'], t6Url($info, $w, $context, 'foreign'), $body);
        $content = (string) $response->getContent();

        expect($response->getStatusCode())->toBe(404, "{$label} should be 404, not {$response->getStatusCode()}");
        foreach ($w['secrets'] as $secret) {
            expect($content)->not->toContain($secret, "{$label} leaked [{$secret}]");
        }
        expect(t6Snapshot())->toBe($before, "{$label} changed the database");

        // The positive control: the same call on the actor's own record is not a 404 (and is a success for who may act),
        // so the URL and body above are valid and the 404 is the isolation's.
        $own = $test->actingAs($actor)->call($info['method'], t6Url($info, $w, $context, 'own'), $body);
        $status = $own->getStatusCode();
        expect($status)->not->toBe(404, "control for {$label}: the actor's own record is a 404")->toBeLessThan(500);
        if ($mayAct) {
            $expected = T6_OWN_REFUSED[$info['name']] ?? null;

            if ($expected === null) {
                expect($status)->toBeLessThan(400, "control for {$label}: the actor's own record should succeed, got {$status}");
            } else {
                expect($status)->toBe($expected, "control for {$label}: the actor's own record should be refused by the business rule ({$expected}), got {$status}");
            }
        }

        $covered[] = "{$info['method']} ".preg_replace('/\{(\w+)\}/', '{$1}', $info['route']->uri()).' ['.$info['name'].']';
    }

    return $covered;
}

/** The routes covered, printed so the test output lists them. */
function t6Print(string $heading, array $covered): void
{
    fwrite(STDERR, "\nT6 {$heading}: ".count($covered)." routes\n  ".implode("\n  ", $covered)."\n");
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

it('T6: an owner of account A gets 404 and no data on every landlord route, for account B\'s records', function (): void {
    $w = t6World();
    $routes = t6Routes('landlord');

    $covered = t6Run($this, $routes, $w, 'landlord', $w['ownerA'], mayAct: true);

    expect($covered)->not->toBeEmpty();
    t6Print('landlord routes, as the owner of A', $covered);
});

it('T6: a caretaker of account A gets 404, not 403, on every landlord route for account B\'s records', function (): void {
    $w = t6World();
    $routes = t6Routes('landlord');

    $covered = t6Run($this, $routes, $w, 'landlord', $w['caretakerA'], mayAct: false);

    expect($covered)->not->toBeEmpty();
    t6Print('landlord routes, as a caretaker of A', $covered);
});

it('T6: renter A gets 404 and no data on every renter route, for renter B\'s reservations and unlisted rooms', function (): void {
    $w = t6World();
    $routes = t6Routes('renter');

    $covered = t6Run($this, $routes, $w, 'renter', $w['renterA'], mayAct: true);

    expect($covered)->not->toBeEmpty();
    t6Print('renter routes, as renter A', $covered);
});

it('T6: fails for a route parameter nobody mapped, and for a body nobody wrote', function (): void {
    // The mechanism itself: a new route behind `landlord` with an unknown parameter must break t6Routes().
    Route::middleware(['web', 'auth', 'landlord'])->get('/landlord/t6-probe/{gadget}', fn () => 'ok')->name('t6.probe.get');
    Route::middleware(['web', 'auth', 'landlord'])->post('/landlord/t6-probe-body/{room}', fn () => 'ok')->name('t6.probe.post');

    $problems = null;

    try {
        t6Routes('landlord');
    } catch (Throwable $e) {
        $problems = $e->getMessage();
    }

    expect($problems)
        ->toContain('{gadget}')->toContain('Extend T6_PARAMETERS')
        ->toContain('t6.probe.post')->toContain('T6_BODIES');
});

it('T6: covers every record route there is today (the list moves only on purpose)', function (): void {
    $names = fn (string $middleware): array => collect(t6Routes($middleware))
        ->filter(fn (array $info): bool => $info['parameters'] !== [])
        ->map(fn (array $info): string => $info['name'])->sort()->values()->all();

    expect($names('landlord'))->toBe([
        'landlord.properties.archive', 'landlord.properties.edit', 'landlord.properties.rooms.create', 'landlord.properties.rooms.store',
        'landlord.properties.show', 'landlord.properties.unarchive', 'landlord.properties.update',
        'landlord.reservations.show',
        'landlord.rooms.archive', 'landlord.rooms.edit', 'landlord.rooms.spaces.store', 'landlord.rooms.unarchive', 'landlord.rooms.update',
        'landlord.spaces.archive', 'landlord.spaces.unarchive', 'landlord.spaces.update',
    ])
        ->and($names('renter'))->toBe(['reservations.show', 'rooms.reservations.store']);
});
