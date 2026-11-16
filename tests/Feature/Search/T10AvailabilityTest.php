<?php

declare(strict_types=1);

use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Space;
use App\Support\Search\ListingSearch;
use App\Support\Search\SearchFilters;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// T10 (BUILD_PLAN): the availability search against the demo data plus hand-made edge cases. For a
// grid of dates x lengths x price ranges x places, the rooms the SQL query returns must equal the
// rooms a brute-force PHP check finds by loading every row and applying docs/RESERVATIONS.md
// literally. The check below is a second, independent statement of the rules on purpose: it does not
// call the scopes the query uses.

const T10_NOW = '2026-11-15 10:00:00'; // Manila; the booking window is Nov 15, 2026 to Feb 13, 2027

/** Reservations on the edge cases the grid has to cross: one room each, so a failure names its cause. */
function t10EdgeCases(): void
{
    $property = Property::factory()->published()->at(placeIn('manila'))->create(['name' => 'Edge House', 'slug' => 'edge-house']);
    $book = fn (Space $space, string $in, string $out, string $state = 'confirmed') => Reservation::factory()->{$state}()->create([
        'space_id' => $space->id, 'move_in' => $in, 'move_out' => $out,
    ]);

    // Back to back: Dec 1 to Jan 1, then Jan 1 to Feb 1. Free on Jan 1 only for a stay that ends by Dec 1.
    $backToBack = Room::factory()->for($property)->create(['name' => 'Back To Back', 'monthly_rate' => 500000]);
    $book(onlySpace($backToBack), '2026-12-01', '2027-01-01');
    $book(onlySpace($backToBack), '2027-01-01', '2027-02-01');

    // A live hold (blocks), and one that ran out a minute ago (doesn't), and one that runs out right now (doesn't: `>` not `>=`).
    $holds = Room::factory()->shared(beds: 3)->for($property)->create(['name' => 'Holds', 'monthly_rate' => 350000]);
    [$one, $two, $three] = $holds->spaces()->orderBy('id')->get()->all();
    Reservation::factory()->create(['space_id' => $one->id, 'move_in' => '2026-12-10', 'move_out' => '2027-02-10', 'hold_expires_at' => now()->addHour()]);
    Reservation::factory()->expiredHold()->create(['space_id' => $two->id, 'move_in' => '2026-12-10', 'move_out' => '2027-02-10', 'hold_expires_at' => now()->subMinute()]);
    Reservation::factory()->create(['space_id' => $three->id, 'move_in' => '2026-12-10', 'move_out' => '2027-02-10', 'hold_expires_at' => now()]);

    // Proof uploaded blocks; cancelled, declined and expired don't.
    $proof = Room::factory()->for($property)->create(['name' => 'Proof', 'monthly_rate' => 600000]);
    $book(onlySpace($proof), '2026-12-15', '2027-01-15', 'proofUploaded');
    $free = Room::factory()->for($property)->create(['name' => 'Not Blocking', 'monthly_rate' => 450000]);
    $book(onlySpace($free), '2026-12-15', '2027-01-15', 'cancelled');
    $book(onlySpace($free), '2026-12-15', '2027-01-15', 'declined');
    $book(onlySpace($free), '2026-12-15', '2027-01-15', 'expired');

    // An archived space is never free, even with no reservation; an archived room is never listed.
    $archivedBed = Room::factory()->shared(beds: 2)->for($property)->create(['name' => 'One Bed Archived', 'monthly_rate' => 300000]);
    [$gone, $kept] = $archivedBed->spaces()->orderBy('id')->get()->all();
    $gone->forceFill(['archived_at' => now()])->save();
    $book($kept, '2026-12-01', '2027-01-01');
    $onlyArchived = Room::factory()->for($property)->create(['name' => 'All Archived', 'monthly_rate' => 320000]);
    onlySpace($onlyArchived)->forceFill(['archived_at' => now()])->save();
    Room::factory()->archived()->for($property)->create(['name' => 'Archived Room', 'monthly_rate' => 310000]);

    // Not listed: a draft property and an unverified account's property (the factory verifies, so undo it).
    $draft = Property::factory()->at(placeIn('quezon-city'))->create(['name' => 'Draft', 'slug' => 'draft']);
    Room::factory()->for($draft)->create(['monthly_rate' => 300000]);
    $unverified = Property::factory()->published()->at(placeIn('quezon-city'))->create(['name' => 'Unverified', 'slug' => 'unverified']);
    DB::table('landlord_accounts')->where('id', $unverified->landlord_account_id)->update(['is_verified' => false]);
    Room::factory()->for($unverified)->create(['monthly_rate' => 300000]);
}

/**
 * The rules, written out again from docs/RESERVATIONS.md and ACCESS.md, over every row.
 *
 * @param  list<array{from: ?string, months: int, min: ?int, max: ?int, city: ?string}>  $combinations
 * @return array<string, list<int>> room ids by combination key
 */
function t10BruteForce(array $combinations): array
{
    $now = CarbonImmutable::now('UTC');
    $accounts = DB::table('landlord_accounts')->pluck('is_verified', 'id')->all();
    $properties = DB::table('properties')->get()->keyBy('id');
    $spaces = DB::table('spaces')->get()->groupBy('room_id');
    $rooms = DB::table('rooms')->orderBy('id')->get();

    // Decided once per row ("now" is frozen): does it block at all?
    $reservations = DB::table('reservations')->get()->each(function (object $r) use ($now): void {
        $r->blocking = in_array($r->status, ['proof_uploaded', 'confirmed', 'active'], true)
            || ($r->status === 'held' && $r->hold_expires_at !== null && CarbonImmutable::parse($r->hold_expires_at, 'UTC')->gt($now));
    })->groupBy('space_id');

    $blocks = fn (object $r, string $in, string $out): bool => $r->blocking && $r->move_in < $out && $r->move_out > $in; // move_out is exclusive on both sides

    $found = [];
    foreach ($combinations as $c) {
        $in = $c['from'];
        $out = $in === null ? null : CarbonImmutable::parse($in)->addMonthsNoOverflow($c['months'])->toDateString();
        $ids = [];

        foreach ($rooms as $room) {
            $property = $properties[$room->property_id];

            if ($room->archived_at !== null || ! $property->is_published || $property->archived_at !== null || ! $accounts[$property->landlord_account_id]) {
                continue;
            }
            if (($c['city'] !== null && $property->city_slug !== $c['city'])
                || ($c['min'] !== null && $room->monthly_rate < $c['min'])
                || ($c['max'] !== null && $room->monthly_rate > $c['max'])) {
                continue;
            }
            if ($in !== null) {
                $free = false;
                foreach ($spaces[$room->id] ?? [] as $space) {
                    if ($space->archived_at !== null) {
                        continue;
                    }
                    $taken = false;
                    foreach ($reservations[$space->id] ?? [] as $r) {
                        $taken = $taken || $blocks($r, $in, (string) $out);
                    }
                    $free = $free || ! $taken;
                }
                if (! $free) {
                    continue;
                }
            }
            $ids[] = $room->id;
        }
        $found[t10Key($c)] = $ids;
    }

    return $found;
}

function t10Key(array $c): string
{
    return json_encode($c, JSON_THROW_ON_ERROR);
}

it('T10: the availability search matches a brute-force check on the demo data and the edge cases', function (): void {
    $this->travelTo(CarbonImmutable::parse(T10_NOW, 'Asia/Manila'));
    Storage::fake('photos');
    importDemoPlaces();
    $this->seed(DemoSeeder::class);
    t10EdgeCases();

    // Boundary days of the edge cases (stay starts and ends, hold expiry), the window's ends, then every 5th day.
    $boundaries = ['2026-11-15', '2026-11-16', '2026-11-30', '2026-12-01', '2026-12-02', '2026-12-09', '2026-12-10', '2026-12-11', '2026-12-14', '2026-12-15', '2026-12-31', '2027-01-01', '2027-01-02', '2027-01-14', '2027-01-15', '2027-01-31', '2027-02-01', '2027-02-09', '2027-02-10', '2027-02-13'];
    $dates = array_values(array_unique([...$boundaries, ...array_map(fn (int $d): string => CarbonImmutable::parse('2026-11-15')->addDays($d)->toDateString(), range(5, 85, 10))]));
    sort($dates);
    $dates[] = null; // no date: price and place only

    $combinations = [];
    foreach ($dates as $from) {
        foreach ($from === null ? [1] : [1, 3, 12] as $months) {
            foreach ([[null, null], [300000, 450000], [null, 350000], [500000, null]] as [$min, $max]) {
                $combinations[] = ['from' => $from, 'months' => $months, 'min' => $min, 'max' => $max, 'city' => null];
            }
            $combinations[] = ['from' => $from, 'months' => $months, 'min' => null, 'max' => 450000, 'city' => 'manila'];
        }
    }

    $expected = t10BruteForce($combinations);
    $mismatches = [];
    $nonEmpty = 0;

    foreach ($combinations as $c) {
        $query = array_filter([
            'city' => $c['city'], 'move_in' => $c['from'], 'months' => $c['from'] === null ? null : (string) $c['months'],
            'min_price' => $c['min'] === null ? null : (string) intdiv($c['min'], 100),
            'max_price' => $c['max'] === null ? null : (string) intdiv($c['max'], 100),
        ]);
        $filters = SearchFilters::fromRequest(Request::create('/listings', 'GET', $query));

        /** @var list<int> $rooms */
        $rooms = ListingSearch::rooms($filters)->orderBy('rooms.id')->pluck('rooms.id')->map(fn (mixed $id): int => (int) $id)->all();
        /** @var list<int> $properties */
        $properties = ListingSearch::properties($filters)->pluck('properties.id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $expectedProperties = array_values(array_unique(Room::query()->withoutGlobalScopes()->whereIn('id', $expected[t10Key($c)])->pluck('property_id')->map(fn (mixed $id): int => (int) $id)->all()));
        sort($expectedProperties);

        $nonEmpty += $rooms === [] ? 0 : 1;
        if ($rooms !== $expected[t10Key($c)] || $properties !== $expectedProperties) {
            $mismatches[] = t10Key($c).' query rooms '.json_encode($rooms).' expected '.json_encode($expected[t10Key($c)]);
        }
    }

    fwrite(STDERR, "\nT10: ".count($combinations)." combinations checked ({$nonEmpty} with at least one room), ".count($mismatches)." mismatches\n");

    expect($mismatches)->toBe([])
        ->and($nonEmpty)->toBeGreaterThan(count($combinations) / 3); // a grid of empty answers would prove nothing
});

it('T10: the edge cases give the answers RESERVATIONS.md says (so the grid is not agreeing on a wrong rule)', function (): void {
    $this->travelTo(CarbonImmutable::parse(T10_NOW, 'Asia/Manila'));
    t10EdgeCases();

    $names = function (string $from, int $months): array {
        $filters = SearchFilters::fromRequest(Request::create('/listings', 'GET', ['city' => 'manila', 'move_in' => $from, 'months' => (string) $months]));

        return ListingSearch::rooms($filters)->pluck('rooms.name')->all();
    };

    // Dec 1 for a month: the first stay of Back To Back takes it.
    expect($names('2026-12-01', 1))->not->toContain('Back To Back')
        // Jan 1 sits between the two stays, but the second starts that day: still taken.
        ->and($names('2027-01-01', 1))->not->toContain('Back To Back')
        // After both: free again, on the very day the second one ends (move_out is exclusive).
        ->and($names('2027-02-01', 1))->toContain('Back To Back')
        // The live hold blocks one bed, the expired and the just-expired ones don't: Holds still has beds.
        ->and($names('2026-12-10', 1))->toContain('Holds')
        // Proof uploaded blocks; cancelled, declined and expired don't.
        ->and($names('2026-12-20', 1))->not->toContain('Proof')->toContain('Not Blocking')
        // An archived space and an archived room never count.
        ->and($names('2027-01-01', 1))->toContain('One Bed Archived')->not->toContain('All Archived')->not->toContain('Archived Room')
        ->and($names('2026-12-20', 1))->not->toContain('One Bed Archived');
});
