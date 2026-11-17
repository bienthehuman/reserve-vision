<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;
use App\Support\Viewings\ViewingSlots;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoPhotos;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

// Self-checks of the demo data (slice 2.2). Each test seeds inside its own
// transaction (about a second), with "now" frozen so the data is the same every day.

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00', 'Asia/Manila'));
    Storage::fake('photos'); // the demo photos are real files (slice 4.2)
    importDemoPlaces(); // the demo cities and their real barangays (slice 4.4)
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

    // Every property has a full address (street, barangay, city, province: the street is only the street)
    // and between 3 and 8 amenities.
    expect(DB::table('properties')->whereNull('address')->orWhere('address', 'like', '%,%')->count())->toBe(0)
        ->and(DB::table('properties')->where('city', 'Cebu City')->where('province', '!=', 'Cebu')->count())->toBe(0)
        ->and(DB::table('properties')->where('city_slug', 'manila')->value('province'))->toBe('Metro Manila')
        ->and(DB::table('property_amenity')->count())->toBeBetween(180, 480);

    // Real places (slice 4.4): every property is linked, and its text columns are copies of the linked rows.
    $copies = DB::table('properties')
        ->join('cities', 'cities.id', '=', 'properties.city_id')
        ->join('provinces', 'provinces.id', '=', 'cities.province_id')
        ->join('barangays', 'barangays.id', '=', 'properties.barangay_id')
        ->whereColumn('barangays.city_id', 'cities.id')
        ->whereColumn('properties.city', 'cities.name')->whereColumn('properties.city_slug', 'cities.slug')
        ->whereColumn('properties.province', 'provinces.name')
        ->whereRaw("properties.barangay = IF(barangays.district IS NULL, barangays.name, CONCAT(barangays.name, ', ', barangays.district))")
        ->count();
    expect($copies)->toBe(60);

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
    // Its URL, /rooms/1, is in the README and the Phase 2 demo test (e2e/reserve.spec.ts).
    expect($room->id)->toBe(1)
        ->and($room->landlord_account_id)->toBe($owner->landlord_account_id)
        ->and($room->room_type)->toBe('shared')
        ->and((bool) $property->is_published)->toBeTrue()
        ->and($property->name)->toBe(DemoSeeder::LAST_BED_PROPERTY)
        ->and("{$property->barangay}, {$property->city}")->toBe('Barangay 395, Sampaloc, Manila')
        ->and($spaces)->toHaveCount(4);

    $reservations = DB::table('reservations')->whereIn('space_id', $spaces->pluck('id'))->get();
    expect($reservations)->toHaveCount(3)
        ->and($reservations->pluck('status')->unique()->all())->toBe(['confirmed'])
        ->and($reservations->pluck('move_in')->unique()->all())->toBe([$firstOfNextMonth])
        ->and($reservations->pluck('space_id')->contains($spaces->last()->id))->toBeFalse();
});

it('does nothing when run again', function (): void {
    $tables = ['users', 'landlord_accounts', 'landlord_members', 'properties', 'property_amenity', 'amenities', 'rooms', 'spaces', 'reservations', 'payment_proofs', 'property_photos'];
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

it('gives every demo property payment instructions, and a first-published date to the published ones', function (): void {
    expect(DB::table('properties')->whereNull('payment_instructions')->orWhere('payment_instructions', '')->count())->toBe(0)
        ->and(DB::table('properties')->where('payment_instructions', 'not like', '%GCash%')->where('payment_instructions', 'not like', '%Maya%')->count())->toBe(0)
        ->and(DB::table('properties')->where('is_published', true)->whereNull('first_published_at')->count())->toBe(0)
        ->and(DB::table('properties')->where('is_published', false)->whereNotNull('first_published_at')->count())->toBe(0);
});

it('gives every published property 3 to 6 ready photos from the curated set, resized like an upload, and none to a draft', function (): void {
    $photos = DB::table('property_photos')->orderBy('property_id')->orderBy('sort')->get();
    $published = DB::table('properties')->where('is_published', true)->pluck('kind', 'id');
    $curated = collect(DemoPhotos::all())->keyBy('alt'); // every curated description is its own

    expect($photos->pluck('status')->unique()->all())->toBe(['ready'])
        ->and($photos->pluck('property_id')->unique()->sort()->values()->all())->toBe($published->keys()->sort()->values()->all())
        ->and($photos->pluck('path')->unique())->toHaveCount($photos->count())
        ->and($curated)->toHaveCount(count(DemoPhotos::all()));

    foreach ($photos->groupBy('property_id') as $propertyId => $ofProperty) {
        $kinds = $ofProperty->map(fn (object $row): string => $curated[$row->alt]['kind'] ?? throw new RuntimeException("Alt text not in photos.php: {$row->alt}"));

        // 3 to 6, in order, one of each kind (so no repeat in a gallery), one building, and rooms that fit the kind.
        expect($ofProperty->count())->toBeBetween(3, 6)
            ->and($ofProperty->pluck('sort')->all())->toBe(range(1, $ofProperty->count()))
            ->and($kinds->unique())->toHaveCount($kinds->count())
            ->and($kinds->contains('exterior'))->toBeTrue()
            ->and($kinds->contains($published[$propertyId] === 'boarding_house' ? 'bunk' : 'apartment'))->toBeTrue()
            ->and($kinds->contains($published[$propertyId] === 'boarding_house' ? 'apartment' : 'bunk'))->toBeFalse();
    }

    // Both kinds of cover are used, so the home page doesn't show one of five facades.
    $covers = $photos->groupBy('property_id')->map(fn ($rows): string => $curated[$rows->first()->alt]['kind']);
    expect($covers->contains('exterior'))->toBeTrue()->and($covers->reject(fn (string $kind): bool => $kind === 'exterior')->isNotEmpty())->toBeTrue();

    // The files: a 480 px wide thumbnail and a full size of at most 1600 px, both WebP, for every row and nothing else.
    $disk = Storage::disk('photos');
    expect($disk->allFiles())->toHaveCount($photos->count() * 2);

    $cover = $photos->first();
    $thumb = getimagesizefromstring($disk->get("{$cover->path}-thumb.webp"));
    $full = getimagesizefromstring($disk->get("{$cover->path}-full.webp"));
    expect($thumb[0])->toBe(480)->and($thumb['mime'])->toBe('image/webp')
        ->and([$full[0], $full[1], $full['mime']])->toBe([$cover->width, $cover->height, 'image/webp'])
        ->and(max($cover->width, $cover->height))->toBeLessThanOrEqual(1600);
});

it('seeds viewing requests across every status, within the limits, in real slots (slice 6.2)', function (): void {
    $viewings = DB::table('viewing_requests')->get();
    $now = CarbonImmutable::parse('2026-11-15 10:00:00', 'Asia/Manila');

    expect($viewings->count())->toBeGreaterThanOrEqual(100)
        ->and($viewings->pluck('status')->unique()->sort()->values()->all())->toBe(['accepted', 'cancelled', 'declined', 'done', 'requested']);

    // Every time is a real slot in Manila: 8:00 AM to 6:00 PM, on the hour or half hour.
    foreach ($viewings as $viewing) {
        $manila = CarbonImmutable::parse($viewing->preferred_at, 'UTC')->setTimezone('Asia/Manila');
        expect(ViewingSlots::isSlot($manila))->toBeTrue("viewing {$viewing->id} at {$manila}")
            ->and($viewing->landlord_account_id)->toBe(DB::table('properties')->where('id', $viewing->property_id)->value('landlord_account_id'));
    }

    // The limits of RequestViewing, written out again: at most 3 open per renter, 1 per property.
    $open = $viewings->filter(fn (object $v): bool => in_array($v->status, ['requested', 'accepted'], true) && CarbonImmutable::parse($v->preferred_at, 'UTC')->greaterThan($now));
    foreach ($open->groupBy('renter_id') as $renterId => $rows) {
        expect($rows->count())->toBeLessThanOrEqual(3, "renter {$renterId}")->and($rows->pluck('property_id')->unique()->count())->toBe($rows->count(), "renter {$renterId}: two open in one property");
    }

    // Answered ones were answered by a member of the property's account; unanswered ones by nobody.
    foreach ($viewings as $viewing) {
        if (in_array($viewing->status, ['accepted', 'declined', 'done'], true)) {
            expect(DB::table('landlord_members')->where('user_id', $viewing->decided_by)->where('landlord_account_id', $viewing->landlord_account_id)->exists())->toBeTrue();
        } else {
            expect($viewing->decided_by)->toBeNull();
        }
    }
});

it('seeds the demo renters and the owner\'s properties with viewings the reviewers see', function (): void {
    $id = fn (string $email) => DB::table('users')->where('email', $email)->value('id');
    $owner = DB::table('landlord_members')->where('user_id', $id(DemoSeeder::OWNER_EMAIL))->value('landlord_account_id');
    $openFor = fn (string $email) => DB::table('viewing_requests')->where('renter_id', $id($email))->whereIn('status', ['requested', 'accepted'])->where('preferred_at', '>', now())->count();

    // renter1 has one place left under the limit of 3 (the Phase 6 demo asks for a viewing as renter1).
    expect($openFor(DemoSeeder::RENTER1_EMAIL))->toBe(2)->and($openFor(DemoSeeder::RENTER2_EMAIL))->toBe(2)
        ->and(DB::table('viewing_requests')->where('renter_id', $id(DemoSeeder::RENTER1_EMAIL))->pluck('status')->unique()->sort()->values()->all())->toBe(['accepted', 'cancelled', 'declined', 'done', 'requested'])
        ->and(DB::table('viewing_requests')->where('landlord_account_id', $owner)->count())->toBeGreaterThanOrEqual(9);

    // A request for today, still to come, waits on the owner's list (the one marked "Today").
    $today = DB::table('viewing_requests')->where('landlord_account_id', $owner)->where('status', 'requested')
        ->whereBetween('preferred_at', [now()->utc()->format('Y-m-d H:i:s'), now('Asia/Manila')->endOfDay()->utc()->format('Y-m-d H:i:s')])->count();
    expect($today)->toBeGreaterThanOrEqual(1);
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
    // Changed in 4.4: real barangays from the PSGC (the `barangay` column), and each property's city and barangay codes.
    // Every other column is what it was: the Faker stream is unchanged (one draw picks the barangay, as before).
    // Slice 5.3: payment_proofs gained mime_type ('image/jpeg' on every demo proof); nothing else changed.
    // Slice 6.2: viewing_requests joined the hash (about 150 random rows and the scripted set); the other tables hash as before.
    // Slice 7.1: real CC0 photos replace the painted ones. property_photos changed (which photo, its alt text, its size), and
    // so did viewing_requests: the photo choice used to draw from the main Faker stream before the viewings were built; it now
    // has a stream of its own (SEED + 1), so the random viewings shifted once. Nothing else draws from the photo stream, so no
    // other table is meant to have changed.
    $expected = '1337ce78b828c3a43b64eb0b8e38d048bae9f6b3e5e8613426c8f54cf48b53ac';

    // Amenities are reference data with auto-increment ids, whose counter survives a
    // rolled-back test, so they are hashed by slug, not by id.
    $queries = [
        'users' => DB::table('users')->orderBy('id'),
        'landlord_accounts' => DB::table('landlord_accounts')->orderBy('id'),
        'landlord_members' => DB::table('landlord_members')->orderBy('id'),
        'amenities' => DB::table('amenities')->select('slug', 'name')->orderBy('slug'),
        // The places tables' ids are auto-increment too (slice 4.4): a property's city and barangay by PSGC code.
        'properties' => DB::table('properties')
            ->join('cities', 'cities.id', '=', 'properties.city_id')->join('barangays', 'barangays.id', '=', 'properties.barangay_id')
            ->select('properties.*', 'cities.psgc_code as city_code', 'barangays.psgc_code as barangay_code')->orderBy('properties.id'),
        'property_amenity' => DB::table('property_amenity')->join('amenities', 'amenities.id', '=', 'amenity_id')
            ->select('property_id', 'slug')->orderBy('property_id')->orderBy('slug'),
        'rooms' => DB::table('rooms')->orderBy('id'),
        'spaces' => DB::table('spaces')->orderBy('id'),
        'reservations' => DB::table('reservations')->orderBy('id'),
        'payment_proofs' => DB::table('payment_proofs')->orderBy('id'),
        'property_photos' => DB::table('property_photos')->orderBy('id'),
        // Slice 6.2.
        'viewing_requests' => DB::table('viewing_requests')->orderBy('id'),
    ];
    $skip = ['password', 'remember_token', 'created_at', 'updated_at', 'city_id', 'barangay_id'];

    $context = hash_init('sha256');
    foreach ($queries as $table => $query) {
        foreach ($query->cursor() as $row) {
            hash_update($context, $table.json_encode(array_diff_key((array) $row, array_flip($skip)), JSON_THROW_ON_ERROR)."\n");
        }
    }

    expect(hash_final($context))->toBe($expected);
});
