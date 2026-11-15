<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Self-checks of the demo data (slice 2.2). Each test seeds inside its own
// transaction (about a second), with "now" frozen so the data is the same every day.

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00', 'Asia/Manila'));
    $this->seed(DemoSeeder::class);
});

/** @return list<array{space_id: int, in: string, out: string, status: string, hold: ?string, renter: int, property: int}> */
function seededReservations(): array
{
    /** @var list<array{space_id: int, in: string, out: string, status: string, hold: ?string, renter: int, property: int}> */
    return DB::table('reservations')
        ->join('spaces', 'spaces.id', '=', 'reservations.space_id')
        ->join('rooms', 'rooms.id', '=', 'spaces.room_id')
        ->orderBy('reservations.id')
        ->get(['reservations.space_id', 'reservations.move_in as in', 'reservations.move_out as out', 'reservations.status', 'reservations.hold_expires_at as hold', 'reservations.renter_id as renter', 'rooms.property_id as property'])
        ->map(fn (object $r): array => (array) $r)
        ->all();
}

/** The blocking rule of docs/RESERVATIONS.md, written out again here on purpose. */
function isBlocking(array $r): bool
{
    return in_array($r['status'], ['proof_uploaded', 'confirmed', 'active'], true)
        || ($r['status'] === 'held' && $r['hold'] !== null && CarbonImmutable::parse($r['hold'], 'UTC')->isFuture());
}

it('creates the promised amounts, places and mix', function (): void {
    expect(DB::table('landlord_accounts')->count())->toBe(15)
        ->and(DB::table('landlord_accounts')->where('is_verified', true)->count())->toBe(12)
        ->and(DB::table('properties')->count())->toBe(60)
        ->and(DB::table('properties')->where('kind', 'boarding_house')->count())->toBe(36)
        ->and(DB::table('properties')->where('kind', 'apartment')->count())->toBe(24)
        ->and(DB::table('properties')->distinct()->count('city'))->toBeGreaterThanOrEqual(6)
        ->and(DB::table('spaces')->count())->toBeBetween(360, 440)
        ->and(DB::table('reservations')->count())->toBeGreaterThanOrEqual(2000)
        ->and(DB::table('amenities')->count())->toBeGreaterThanOrEqual(12);

    // Every property sits on the map and has between 3 and 8 amenities.
    expect(DB::table('properties')->whereNull('lat')->orWhereNull('lng')->count())->toBe(0)
        ->and(DB::table('property_amenity')->count())->toBeBetween(180, 480);

    // Apartments: one whole-room space per unit. Boarding houses: shared rooms of 2-8 beds.
    $apartmentSpaces = DB::table('spaces')->join('rooms', 'rooms.id', '=', 'spaces.room_id')->join('properties', 'properties.id', '=', 'rooms.property_id')
        ->where('properties.kind', 'apartment')->where('spaces.label', '!=', 'Whole room')->count();
    $beds = DB::table('spaces')->join('rooms', 'rooms.id', '=', 'spaces.room_id')->where('rooms.room_type', 'shared')
        ->select('rooms.id', DB::raw('count(*) as n'))->groupBy('rooms.id')->pluck('n');
    expect($apartmentSpaces)->toBe(0)
        ->and($beds->min())->toBeGreaterThanOrEqual(2)
        ->and($beds->max())->toBeLessThanOrEqual(8);
});

it('uses realistic money and only example.com emails', function (): void {
    $bed = DB::table('rooms')->where('room_type', 'shared');
    expect((clone $bed)->min('monthly_rate'))->toBeGreaterThanOrEqual(250000)
        ->and((clone $bed)->max('monthly_rate'))->toBeLessThanOrEqual(550000)
        ->and((clone $bed)->whereRaw('monthly_rate % 5000 != 0')->count())->toBe(0)
        ->and(DB::table('rooms')->where('room_type', 'private')->min('monthly_rate'))->toBeGreaterThanOrEqual(500000)
        ->and(DB::table('rooms')->where('room_type', 'private')->max('monthly_rate'))->toBeLessThanOrEqual(2800000)
        ->and(DB::table('rooms')->where('room_type', 'private')->whereRaw('monthly_rate % 10000 != 0')->count())->toBe(0)
        ->and(DB::table('rooms')->whereColumn('reservation_fee', '>', 'monthly_rate')->count())->toBe(0)
        ->and(DB::table('rooms')->where('reservation_fee', '<', 50000)->count())->toBe(0)
        ->and(DB::table('rooms')->where('deposit_months', '>', 2)->orWhere('advance_months', '>', 2)->count())->toBe(0)
        ->and(DB::table('users')->where('email', 'not like', '%@example.com')->count())->toBe(0)
        ->and(DB::table('users')->whereRaw("phone NOT REGEXP '^09[0-9]{9}$'")->count())->toBe(0);
});

it('never lets two blocking reservations overlap on a space (brute force)', function (): void {
    // Stricter than the blocking rule: stays that already ended must not overlap either.
    $occupying = [];
    foreach (seededReservations() as $r) {
        if (isBlocking($r) || $r['status'] === 'ended') {
            $occupying[$r['space_id']][] = $r;
        }
    }

    $pairs = 0;
    foreach ($occupying as $stays) {
        foreach ($stays as $i => $a) {
            foreach (array_slice($stays, $i + 1) as $b) {
                $pairs++;
                // Overlap rule of docs/RESERVATIONS.md: a.in < b.out AND b.in < a.out.
                expect($a['in'] < $b['out'] && $b['in'] < $a['out'])->toBeFalse("space {$a['space_id']}: {$a['in']}–{$a['out']} overlaps {$b['in']}–{$b['out']}");
            }
        }
    }

    expect($pairs)->toBeGreaterThan(1000);
});

it('covers every status, including live and expired holds', function (): void {
    $counts = DB::table('reservations')->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
    foreach (ReservationStatus::cases() as $status) {
        expect($counts[$status->value] ?? 0)->toBeGreaterThan(10, "status {$status->value}");
    }

    $held = array_filter(seededReservations(), fn (array $r): bool => $r['status'] === 'held');
    $live = array_filter($held, isBlocking(...));
    expect(count($live))->toBeGreaterThan(10)
        ->and(count($held) - count($live))->toBeGreaterThan(10);

    // Everything is within about 18 months back and 90 days ahead for move-in.
    expect(DB::table('reservations')->min('move_in'))->toBeGreaterThanOrEqual('2025-05-01')
        ->and(DB::table('reservations')->max('move_in'))->toBeLessThanOrEqual('2027-02-14');
});

it('keeps renters within the open-reservation limits', function (): void {
    $open = [];
    foreach (seededReservations() as $r) {
        if ($r['status'] === 'proof_uploaded' || ($r['status'] === 'held' && isBlocking($r))) {
            $open[$r['renter']][] = $r['property'];
        }
    }

    foreach ($open as $renter => $properties) {
        expect(count($properties))->toBeLessThanOrEqual(2, "renter {$renter}")
            ->and(count(array_unique($properties)))->toBe(count($properties), "renter {$renter}");
    }
});

it('keeps every child row under its parent\'s landlord account', function (): void {
    // The composite foreign keys already guarantee it; assert it anyway.
    expect(DB::table('rooms')->join('properties', 'properties.id', '=', 'rooms.property_id')->whereColumn('rooms.landlord_account_id', '!=', 'properties.landlord_account_id')->count())->toBe(0)
        ->and(DB::table('spaces')->join('rooms', 'rooms.id', '=', 'spaces.room_id')->whereColumn('spaces.landlord_account_id', '!=', 'rooms.landlord_account_id')->count())->toBe(0)
        ->and(DB::table('reservations')->join('spaces', 'spaces.id', '=', 'reservations.space_id')->whereColumn('reservations.landlord_account_id', '!=', 'spaces.landlord_account_id')->count())->toBe(0)
        ->and(DB::table('payment_proofs')->join('reservations', 'reservations.id', '=', 'payment_proofs.reservation_id')->whereColumn('payment_proofs.landlord_account_id', '!=', 'reservations.landlord_account_id')->count())->toBe(0);
});

it('has no published property and no reservation on an unverified account', function (): void {
    $unverified = DB::table('landlord_accounts')->where('is_verified', false)->pluck('id');

    expect($unverified)->toHaveCount(3)
        ->and(DB::table('properties')->whereIn('landlord_account_id', $unverified)->count())->toBe(6)
        ->and(DB::table('properties')->whereIn('landlord_account_id', $unverified)->where('is_published', true)->count())->toBe(0)
        ->and(DB::table('reservations')->whereIn('landlord_account_id', $unverified)->count())->toBe(0);
});

it('creates the demo logins and the last-bed room', function (): void {
    $user = fn (string $email): object => DB::table('users')->where('email', $email)->first();
    $member = fn (string $email): object => DB::table('landlord_members')->where('user_id', $user($email)->id)->first();

    foreach (['owner@example.com', 'caretaker@example.com', 'owner2@example.com', 'renter1@example.com', 'renter2@example.com'] as $email) {
        expect($user($email)->email_verified_at)->not->toBeNull()
            ->and(Hash::check('password', $user($email)->password))->toBeTrue();
    }

    $owner = $member('owner@example.com');
    $caretaker = $member('caretaker@example.com');
    $owner2 = $member('owner2@example.com');
    expect($owner->role)->toBe('owner')
        ->and($caretaker->role)->toBe('caretaker')
        ->and($caretaker->landlord_account_id)->toBe($owner->landlord_account_id)
        ->and($owner2->landlord_account_id)->not->toBe($owner->landlord_account_id)
        ->and(DB::table('landlord_accounts')->whereIn('id', [$owner->landlord_account_id, $owner2->landlord_account_id])->where('is_verified', true)->count())->toBe(2)
        ->and(DB::table('properties')->where('landlord_account_id', $owner->landlord_account_id)->count())->toBeBetween(4, 6)
        ->and(DB::table('landlord_members')->where('user_id', $user('renter1@example.com')->id)->exists())->toBeFalse()
        ->and(DB::table('reservations')->whereIn('renter_id', [$user('renter1@example.com')->id, $user('renter2@example.com')->id])->count())->toBe(0);

    // The "last bed" room: published, 4 beds, 3 confirmed from next month's 1st, one free.
    $room = DB::table('rooms')->where('name', DemoSeeder::LAST_BED_ROOM)->first();
    $property = DB::table('properties')->where('id', $room->property_id)->first();
    $firstOfNextMonth = '2026-12-01';
    $spaces = DB::table('spaces')->where('room_id', $room->id)->orderBy('label')->get();
    expect($room->landlord_account_id)->toBe($owner->landlord_account_id)
        ->and($room->room_type)->toBe('shared')
        ->and((bool) $property->is_published)->toBeTrue()
        ->and($property->name)->toBe(DemoSeeder::LAST_BED_PROPERTY)
        ->and($spaces)->toHaveCount(4);

    $reservations = DB::table('reservations')->whereIn('space_id', $spaces->pluck('id'))->get();
    expect($reservations)->toHaveCount(3)
        ->and($reservations->pluck('status')->unique()->all())->toBe(['confirmed'])
        ->and($reservations->pluck('move_in')->unique()->all())->toBe([$firstOfNextMonth])
        ->and($reservations->pluck('space_id')->contains($spaces->last()->id))->toBeFalse();
});

it('does nothing when run again', function (): void {
    $tables = ['users', 'landlord_accounts', 'landlord_members', 'properties', 'property_amenity', 'amenities', 'rooms', 'spaces', 'reservations', 'payment_proofs'];
    $count = fn (): array => array_map(fn (string $t): int => DB::table($t)->count(), $tables);
    $before = $count();

    $this->seed(DemoSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect($count())->toBe($before);
});

it('refuses to run in production without APP_DEMO', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['app.demo' => false]);

    expect(fn () => (new DemoSeeder)->run())->toThrow(RuntimeException::class, 'APP_DEMO');

    config(['app.demo' => true]);
    $before = DB::table('users')->count();
    (new DemoSeeder)->run(); // already seeded: allowed to run, and a no-op
    expect(DB::table('users')->count())->toBe($before);
});

it('produces the same business data every time', function (): void {
    /*
     * A hash of every business column (ids included, passwords, tokens and
     * created_at/updated_at excluded) of the seeded tables, with "now" frozen
     * above. If you change the seeder ON PURPOSE (different counts, prices, a new
     * column), run this test, copy the "Received" hash it prints into EXPECTED,
     * and say why in the PR. An unexpected change means something stopped being
     * deterministic: look for an unseeded random call or an unordered query.
     */
    $expected = '31f8e3f2a360d570ea1bed84900bf49d4bfeb33b7c04de4fd41a6c92410835f1';

    // Amenities are reference data with auto-increment ids, whose counter survives a
    // rolled-back test, so they are hashed by slug, not by id.
    $queries = [
        'users' => DB::table('users')->orderBy('id'),
        'landlord_accounts' => DB::table('landlord_accounts')->orderBy('id'),
        'landlord_members' => DB::table('landlord_members')->orderBy('id'),
        'amenities' => DB::table('amenities')->select('slug', 'name')->orderBy('slug'),
        'properties' => DB::table('properties')->orderBy('id'),
        'property_amenity' => DB::table('property_amenity')->join('amenities', 'amenities.id', '=', 'amenity_id')
            ->select('property_id', 'slug')->orderBy('property_id')->orderBy('slug'),
        'rooms' => DB::table('rooms')->orderBy('id'),
        'spaces' => DB::table('spaces')->orderBy('id'),
        'reservations' => DB::table('reservations')->orderBy('id'),
        'payment_proofs' => DB::table('payment_proofs')->orderBy('id'),
    ];
    $skip = ['password', 'remember_token', 'created_at', 'updated_at'];

    $context = hash_init('sha256');
    foreach ($queries as $table => $query) {
        foreach ($query->cursor() as $row) {
            hash_update($context, $table.json_encode(array_diff_key((array) $row, array_flip($skip)), JSON_THROW_ON_ERROR)."\n");
        }
    }

    expect(hash_final($context))->toBe($expected);
});
