<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Data\ProcessedPhoto;
use App\Enums\GenderPolicy;
use App\Enums\LandlordRole;
use App\Enums\NameSuffix;
use App\Enums\PaymentProofStatus;
use App\Enums\PhotoStatus;
use App\Enums\PropertyKind;
use App\Enums\ReservationStatus;
use App\Enums\RoomType;
use App\Enums\UserType;
use App\Enums\ViewingRequestStatus;
use App\Models\Barangay;
use App\Models\PaymentProof;
use App\Models\PropertyPhoto;
use App\Support\Photos\PhotoProcessor;
use App\Support\Viewings\ViewingSlots;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoPhotoPainter;
use Database\Seeders\Demo\DemoReceiptPainter;
use Database\Seeders\Demo\StayScheduler;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Deterministic demo data (slice 2.2): 15 landlord accounts, 60 properties,
 * about 400 spaces and a couple of thousand reservations in every status.
 *
 * - Runs as `app` (inserts only); safe to run on every `bin/setup`: it does
 *   nothing when the demo owner already exists, and never truncates.
 * - Seeded Faker plus one anchor ("now" and "today in Asia/Manila", taken
 *   once), so two runs on empty databases give the same rows apart from the
 *   timestamps. Ids are assigned here (max + 1) so the rows don't depend on
 *   auto-increment counters either.
 * - Reservations come from StayScheduler, not ReserveSpace (slice 2.3).
 * - Every published property gets 3 to 6 ready photos (slice 4.2), painted by
 *   DemoPhotoPainter and resized by the same PhotoProcessor as an upload. The
 *   files go to the photos disk before the transaction commits. Their paths
 *   come from the seeded Faker too, so a reseed overwrites the same files.
 * - Places are real (slice 4.4): the cities of `database/data/places.php` and their
 *   barangays from the places tables, so `php artisan places:import` runs first.
 * - Viewing requests (slice 6.2) come last, from the same Faker stream, so nothing above shifts: a scripted set
 *   for the demo owner's properties and renter1 and renter2 (open ones in every state, and past ones; renter1 keeps a
 *   free place under the limit of 3, which the Phase 6 demo uses), then about 150 more across the pool.
 * - Refuses to run in production unless APP_DEMO=true.
 *
 * Tables are filled with chunked inserts. Money is integer centavos.
 *
 * @phpstan-type DemoPlace array{city_id: int, city: string, slug: string, province: string, rate: float, barangays: list<array{id: int, label: string}>, lastBed: array{id: int, label: string}|null}
 */
final class DemoSeeder extends Seeder
{
    public const SEED = 20261115;

    public const OWNER_EMAIL = 'owner@example.com';

    public const CARETAKER_EMAIL = 'caretaker@example.com';

    public const OWNER2_EMAIL = 'owner2@example.com';

    public const RENTER1_EMAIL = 'renter1@example.com';

    public const RENTER2_EMAIL = 'renter2@example.com';

    public const LAST_BED_ROOM = 'Last Bed Room';

    public const LAST_BED_PROPERTY = 'Santos Bedspace House';

    /** The "last bed" house is in Manila's Sampaloc district: its first barangay by PSGC code ("Barangay 395, Sampaloc"). */
    public const LAST_BED_DISTRICT = 'Sampaloc';

    private const RENTER_POOL = 700;

    private const PROPERTIES_PER_ACCOUNT = [5, 4, 5, 5, 5, 5, 5, 4, 4, 4, 4, 4, 2, 2, 2];

    private const VERIFIED_ACCOUNTS = 12;

    private const CHUNK = 400;

    private Generator $faker;

    private CarbonImmutable $now;

    private CarbonImmutable $today;

    private string $passwordHash;

    /** @var array<string, int> */
    private array $lastId = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $rows = [];

    /** @var list<array{id: int, owner: int, caretaker: ?int, verified: bool}> */
    private array $accounts = [];

    /** @var list<int> */
    private array $renterIds = [];

    /** @var array<int, list<array{string, string}>> renter id => blocking stays */
    private array $renterStays = [];

    /** @var array<int, list<int>> renter id => property ids of open reservations */
    private array $renterOpen = [];

    private int $userCounter = 0;

    /** @var array<string, true> */
    private array $usedSlugs = [];

    /** @var array{female: list<string>, male: list<string>, surnames: list<string>} */
    private array $names;

    public function run(): void
    {
        if (app()->environment('production') && ! config('app.demo')) {
            throw new RuntimeException('Refusing to seed demo data in production. Set APP_DEMO=true to allow it.');
        }

        $this->call(AmenitySeeder::class);

        if (DB::table('users')->where('email', self::OWNER_EMAIL)->exists()) {
            $this->command?->info('Demo data is already present; nothing to do.');

            return;
        }

        DB::transaction(function (): void {
            $this->prepare();
            $this->buildPeople();
            $this->buildListings();
            $this->buildReservations();
            $this->buildPhotos();
            $this->buildViewings();
            $this->flush();
            $this->writePhotoFiles();
            $this->writeProofFiles();
        });

        $this->command?->info('Seeded demo data.');
    }

    private function prepare(): void
    {
        $this->faker = Factory::create('en_PH');
        $this->faker->seed(self::SEED);
        $this->now = CarbonImmutable::now('Asia/Manila');
        $this->today = $this->now->startOfDay();
        $this->passwordHash = Hash::make('password');
        /** @var array{female: list<string>, male: list<string>, surnames: list<string>} $names */
        $names = require database_path('data/names.php');
        $this->names = $names;

        foreach (['users', 'landlord_accounts', 'landlord_members', 'properties', 'rooms', 'spaces', 'reservations', 'payment_proofs', 'property_photos', 'viewing_requests'] as $table) {
            $max = DB::table($table)->max('id');
            $this->lastId[$table] = is_numeric($max) ? (int) $max : 0;
            $this->rows[$table] = [];
        }
        $this->rows['property_amenity'] = [];
    }

    // ---------------------------------------------------------------- people

    private function buildPeople(): void
    {
        $fixed = [
            ['owner@example.com', 'Ricardo', 'Mateo', 'Santos', 'Santos Family Rentals', 'santos-rentals'],
            ['owner2@example.com', 'Teresita', null, 'Cruz', 'Cruz Lettings', 'cruz-lettings'],
        ];

        foreach (range(0, 14) as $i) {
            $verified = $i < self::VERIFIED_ACCOUNTS;

            if ($i < 2) {
                [$email, $first, $middle, $last, $name, $slug] = $fixed[$i];
                $owner = $this->addUser(UserType::Landlord, $first, $middle, $last, null, $email);
            } else {
                $person = $this->person();
                $owner = $this->addUser(UserType::Landlord, $person['first'], $person['middle'], $person['last'], $person['suffix']);
                $name = $person['last'].' '.$this->pick(['Rentals', 'Property Holdings', 'Lettings', 'Realty']);
                $slug = $this->uniqueSlug(Str::slug($name));
            }

            $accountId = $this->nextId('landlord_accounts');
            $created = $this->now->subDays($this->int(540, 640));
            $this->rows['landlord_accounts'][] = [
                'id' => $accountId, 'name' => $name, 'slug' => $slug,
                'contact_phone' => $this->phone(), 'is_verified' => $verified,
                'verified_at' => $verified ? $this->ts($created->addDays($this->int(1, 14))) : null,
                'created_at' => $this->ts($created), 'updated_at' => $this->ts($created),
            ];
            $this->addMember($accountId, $owner, LandlordRole::Owner);

            $caretaker = null;
            if ($i === 0) {
                $caretaker = $this->addUser(UserType::Landlord, 'Elena', null, 'Reyes', null, self::CARETAKER_EMAIL);
            } elseif ($verified && $this->chance(40)) {
                $person = $this->person();
                $caretaker = $this->addUser(UserType::Landlord, $person['first'], $person['middle'], $person['last'], $person['suffix']);
            }
            if ($caretaker !== null) {
                $this->addMember($accountId, $caretaker, LandlordRole::Caretaker);
            }

            $this->accounts[] = ['id' => $accountId, 'owner' => $owner, 'caretaker' => $caretaker, 'verified' => $verified];
        }

        $this->addUser(UserType::Renter, 'Jasmine', null, 'Ocampo', null, self::RENTER1_EMAIL);
        $this->addUser(UserType::Renter, 'Carlo', 'Dela Cruz', 'Bautista', null, self::RENTER2_EMAIL);

        foreach (range(1, self::RENTER_POOL) as $unused) {
            $person = $this->person();
            $this->renterIds[] = $this->addUser(UserType::Renter, $person['first'], $person['middle'], $person['last'], $person['suffix']);
        }
    }

    /**
     * @return array{first: string, middle: ?string, last: string, suffix: ?string}
     */
    private function person(): array
    {
        $male = $this->chance(50);

        return [
            'first' => $this->pick($male ? $this->names['male'] : $this->names['female']),
            'middle' => $this->chance(40) ? $this->pick($this->names['surnames']) : null,
            'last' => $this->pick($this->names['surnames']),
            'suffix' => $male && $this->chance(6)
                ? $this->pick([NameSuffix::Jr, NameSuffix::Sr, NameSuffix::Ii, NameSuffix::Iii])->value
                : null,
        ];
    }

    private function addUser(UserType $type, string $first, ?string $middle, string $last, ?string $suffix, ?string $email = null): int
    {
        $id = $this->nextId('users');
        $this->userCounter++;
        $created = $this->now->subDays($this->int(600, 700));
        $this->rows['users'][] = [
            'id' => $id,
            'first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'suffix' => $suffix,
            'email' => $email ?? Str::slug(Str::ascii("{$first} {$last}"), '.').'.'.$this->userCounter.'@example.com',
            'phone' => $this->phone(),
            'type' => $type->value,
            'password' => $this->passwordHash,
            'email_verified_at' => $this->ts($created),
            'remember_token' => null,
            'created_at' => $this->ts($created), 'updated_at' => $this->ts($created),
        ];

        return $id;
    }

    private function addMember(int $accountId, int $userId, LandlordRole $role): void
    {
        $this->rows['landlord_members'][] = [
            'id' => $this->nextId('landlord_members'), 'landlord_account_id' => $accountId,
            'user_id' => $userId, 'role' => $role->value,
            'created_at' => $this->ts($this->now->subDays(600)), 'updated_at' => $this->ts($this->now->subDays(600)),
        ];
    }

    private function phone(): string
    {
        $prefix = $this->pick(['0905', '0915', '0917', '0918', '0927', '0935', '0945', '0956', '0966', '0977', '0995']);

        // Stored normalized, as registration does (App\Support\PhilippineMobile).
        return sprintf('%s%03d%04d', $prefix, $this->int(0, 999), $this->int(0, 9999));
    }

    // -------------------------------------------------------------- listings

    /** @var list<array{id: int, account: int, kind: string, published: bool, name: string, created: CarbonImmutable}> */
    private array $listings = [];

    /** @var list<array{path: string, scene: string, palette: string}> */
    private array $photoFiles = [];

    /** @var array<string, ProcessedPhoto> painted and resized once per process: "scene/palette" */
    private static array $paintings = [];

    /** @var list<array{id: int, room: int, account: int, property: int, label: string, fee: int, special: bool}> */
    private array $spaceRefs = [];

    private function buildListings(): void
    {
        $places = $this->places();
        /** @var array<string, string> $catalogue */
        $catalogue = require database_path('data/amenities.php');
        $amenityIds = array_values(array_map(
            fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            DB::table('amenities')->whereIn('slug', array_keys($catalogue))->orderBy('id')->pluck('id')->all(),
        ));

        // 60 properties: 36 boarding houses, 24 apartments. The very first one
        // is the demo owner's "last bed" house, so it is a boarding house in Manila.
        $kinds = $this->shuffled([...array_fill(0, 35, PropertyKind::BoardingHouse), ...array_fill(0, 24, PropertyKind::Apartment)]);
        array_unshift($kinds, PropertyKind::BoardingHouse);
        $cityOrder = [];
        foreach (range(0, 59) as $i) {
            $cityOrder[] = $places[$i % count($places)];
        }
        $cityOrder = $this->shuffled($cityOrder);
        $cityOrder[0] = $places[0];

        $index = 0;
        foreach ($this->accounts as $accountIndex => $account) {
            foreach (range(1, self::PROPERTIES_PER_ACCOUNT[$accountIndex]) as $unused) {
                $this->addProperty($account, $kinds[$index], $cityOrder[$index], $amenityIds, $index === 0);
                $index++;
            }
        }
    }

    /**
     * The demo cities with their provinces and barangays (ordered by PSGC code, which no collation changes).
     * One draw per property picks the barangay, as one draw picked it from a list of names before 4.4,
     * so every later draw is what it was.
     *
     * @return list<DemoPlace>
     */
    private function places(): array
    {
        /** @var list<array{slug: string, rate: float}> $demoCities */
        $demoCities = require database_path('data/places.php');

        return array_map(function (array $demo): array {
            $city = DB::table('cities')->join('provinces', 'provinces.id', '=', 'cities.province_id')
                ->where('cities.slug', $demo['slug'])
                ->first(['cities.id', 'cities.name', 'cities.slug', 'provinces.name as province'])
                ?? throw new RuntimeException("No city [{$demo['slug']}] in the places tables: run php artisan places:import first.");
            $barangays = DB::table('barangays')->where('city_id', $city->id)->orderBy('psgc_code')->get(['id', 'name', 'district'])
                ->map(fn (object $row): array => ['id' => self::toInt($row->id), 'label' => Barangay::labelOf(self::toString($row->name), is_string($row->district) ? $row->district : null), 'district' => $row->district])
                ->all();

            return [
                'city_id' => self::toInt($city->id), 'city' => self::toString($city->name), 'slug' => self::toString($city->slug),
                'province' => self::toString($city->province), 'rate' => $demo['rate'],
                'barangays' => array_values(array_map(fn (array $b): array => ['id' => $b['id'], 'label' => $b['label']], $barangays)),
                'lastBed' => array_values(array_map(fn (array $b): array => ['id' => $b['id'], 'label' => $b['label']], array_filter($barangays, fn (array $b): bool => $b['district'] === self::LAST_BED_DISTRICT)))[0] ?? null,
            ];
        }, $demoCities);
    }

    /**
     * @param  array{id: int, owner: int, caretaker: ?int, verified: bool}  $account
     * @param  DemoPlace  $place
     * @param  list<int>  $amenityIds
     */
    private function addProperty(array $account, PropertyKind $kind, array $place, array $amenityIds, bool $lastBedHouse): void
    {
        $id = $this->nextId('properties');
        $boarding = $kind === PropertyKind::BoardingHouse;
        $barangay = $lastBedHouse
            ? ($place['lastBed'] ?? throw new RuntimeException('No barangay in Sampaloc, Manila: run php artisan places:import first.'))
            : $this->pick($place['barangays']);
        $surname = $this->pick($this->names['surnames']);

        $name = $lastBedHouse
            ? self::LAST_BED_PROPERTY
            : ($boarding
                ? $surname.' '.$this->pick(['Bedspace House', 'Boarding House', 'Dormitory', 'Rooms for Rent'])
                : $surname.' '.$this->pick(['Apartments', 'Residences', 'Studio Units', 'Townhomes']));
        $slug = $this->uniqueSlug($place['slug'].'/'.Str::slug($name));
        $slug = Str::after($slug, '/');

        $gender = $boarding
            ? $this->weighted([GenderPolicy::Any->value => 55, GenderPolicy::FemaleOnly->value => 25, GenderPolicy::MaleOnly->value => 20])
            : $this->weighted([GenderPolicy::Any->value => 90, GenderPolicy::FemaleOnly->value => 5, GenderPolicy::MaleOnly->value => 5]);
        $published = $account['verified'] && ($lastBedHouse || $this->chance(90));
        $created = $this->now->subDays($this->int(500, 560));

        $street = $this->int(1, 480).' '.$this->pick(['Mabini', 'Rizal', 'Bonifacio', 'Luna', 'Del Pilar', 'Mabuhay', 'Sampaguita', 'Narra', 'Acacia', 'Kalayaan']).' Street';
        // Two draws that used to place the property on a map (lat and lng, dropped with the address change).
        // They stay so every later draw, and with it the rest of the demo data, is what it was.
        $this->int(-1000, 1000);
        $this->int(-1000, 1000);

        $this->rows['properties'][] = [
            'id' => $id, 'landlord_account_id' => $account['id'], 'kind' => $kind->value,
            'name' => $name, 'slug' => $slug,
            'address' => $street,
            'barangay' => $barangay['label'], 'city' => $place['city'], 'city_slug' => $place['slug'], 'province' => $place['province'],
            'city_id' => $place['city_id'], 'barangay_id' => $barangay['id'],
            'gender_policy' => $gender,
            'house_rules' => $this->houseRules($boarding),
            'payment_instructions' => $this->paymentInstructions($id, $surname),
            'is_published' => $published,
            'first_published_at' => $published ? $this->ts($created) : null,
            'archived_at' => null,
            'created_at' => $this->ts($created), 'updated_at' => $this->ts($created),
        ];

        foreach (array_slice($this->shuffled($amenityIds), 0, $this->int(3, 8)) as $amenityId) {
            $this->rows['property_amenity'][] = ['property_id' => $id, 'amenity_id' => $amenityId];
        }

        $rooms = [];
        if ($lastBedHouse) {
            $rooms[] = ['name' => self::LAST_BED_ROOM, 'type' => RoomType::Shared, 'spaces' => 4, 'special' => true];
            $rooms[] = ['name' => 'Room 2', 'type' => RoomType::Shared, 'spaces' => 6, 'special' => false];
            $rooms[] = ['name' => 'Room 3', 'type' => RoomType::Private, 'spaces' => 1, 'special' => false];
        } elseif ($boarding) {
            $number = 1;
            foreach (range(1, $this->pick([1, 2, 2])) as $unused) {
                $rooms[] = ['name' => 'Room '.$number++, 'type' => RoomType::Shared, 'spaces' => $this->pick([2, 3, 4, 4, 6, 8]), 'special' => false];
            }
            foreach (range(1, $this->pick([0, 1, 1, 2])) as $unused) {
                $rooms[] = ['name' => 'Room '.$number++, 'type' => RoomType::Private, 'spaces' => 1, 'special' => false];
            }
        } else {
            foreach (range(1, $this->int(2, 4)) as $unit) {
                $rooms[] = ['name' => 'Unit '.$unit.$this->pick(['A', 'B']), 'type' => RoomType::Private, 'spaces' => 1, 'special' => false];
            }
        }

        foreach ($rooms as $room) {
            $this->addRoom($account['id'], $id, $kind, $room['name'], $room['type'], $room['spaces'], $room['special'], $place['rate'], $created);
        }

        $this->listings[] = ['id' => $id, 'account' => $account['id'], 'kind' => $kind->value, 'published' => $published, 'name' => $name, 'created' => $created];
    }

    private function addRoom(int $accountId, int $propertyId, PropertyKind $kind, string $name, RoomType $type, int $spaces, bool $special, float $cityRate, CarbonImmutable $created): void
    {
        $bed = $type === RoomType::Shared;
        [$low, $high] = match (true) {
            $bed => [2500, 5500],
            $kind === PropertyKind::Apartment => [8000, 28000],
            default => [5000, 12000],
        };
        $step = $bed ? 50 : 100;

        $triangle = ($this->int(0, 1000) + $this->int(0, 1000)) / 2000;
        $pesos = (int) round(($low + ($high - $low) * $triangle) * $cityRate / $step) * $step;
        $rate = min($high, max($low, $pesos)) * 100;
        if ($special) {
            $rate = 350000;
        }

        $fee = (int) round($rate * $this->int(20, 100) / 100 / ($step * 100)) * $step * 100;
        $fee = min($rate, max(50000, $fee));

        $roomId = $this->nextId('rooms');
        $this->rows['rooms'][] = [
            'id' => $roomId, 'property_id' => $propertyId, 'landlord_account_id' => $accountId,
            'name' => $name, 'room_type' => $type->value, 'monthly_rate' => $rate,
            'deposit_months' => $this->pick([0, 1, 1, 2]), 'advance_months' => $this->pick([0, 1, 1, 2]),
            'reservation_fee' => $fee, 'archived_at' => null,
            'created_at' => $this->ts($created), 'updated_at' => $this->ts($created),
        ];

        foreach (range(0, $spaces - 1) as $n) {
            $spaceId = $this->nextId('spaces');
            $label = $bed ? 'Bed '.chr(ord('A') + $n) : 'Whole room';
            $this->rows['spaces'][] = [
                'id' => $spaceId, 'room_id' => $roomId, 'landlord_account_id' => $accountId, 'label' => $label,
                'archived_at' => null, 'created_at' => $this->ts($created), 'updated_at' => $this->ts($created),
            ];
            $this->spaceRefs[] = ['id' => $spaceId, 'room' => $roomId, 'account' => $accountId, 'property' => $propertyId, 'label' => $label, 'fee' => $fee, 'special' => $special];
        }
    }

    /**
     * Where to send the reservation fee. Derived from the property id, not drawn
     * from the random generator, so adding it didn't change any other seeded value.
     */
    private function paymentInstructions(int $id, string $surname): string
    {
        $mobile = sprintf('09%02d %03d %04d', 17 + $id % 9, ($id * 37) % 1000, ($id * 7919) % 10000);
        $bank = sprintf('%04d-%04d-%02d', 1000 + ($id * 13) % 9000, 1000 + ($id * 29) % 9000, ($id * 7) % 100);
        $holder = chr(ord('A') + $id % 26).'. '.$surname;

        return match ($id % 3) {
            0 => "GCash {$mobile} ({$holder}).",
            1 => "GCash {$mobile} ({$holder}) or BPI {$bank}.",
            default => "Maya {$mobile} ({$holder}). Send the exact reservation fee, then upload the screenshot here.",
        };
    }

    private function houseRules(bool $boarding): string
    {
        $parts = [
            $this->pick([
                'The reservation fee is refundable if the landlord declines the reservation.',
                'The reservation fee is non-refundable once the proof of payment is confirmed.',
                'The reservation fee is refundable up to 7 days before move-in.',
            ]),
            $this->pick(['Quiet hours from 10 PM to 6 AM.', 'No smoking inside the building.', 'Keep common areas clean.']),
        ];
        if ($boarding) {
            $parts[] = $this->pick(['Curfew at 11 PM.', 'Visitors allowed in the common area only.', 'No cooking in the rooms.']);
        } else {
            $parts[] = $this->pick(['Pets need the landlord\'s approval.', 'Utilities are billed monthly.', 'One month advance and one month deposit.']);
        }

        return implode(' ', $parts);
    }

    // ---------------------------------------------------------- reservations

    private function buildReservations(): void
    {
        $scheduler = new StayScheduler(fn (int $min, int $max): int => $this->int($min, $max));
        $from = $this->today->subMonths(18);
        $lastMoveIn = $this->today->addDays(90);

        /** @var array<int, bool> $bookable property id => published */
        $bookable = [];
        foreach ($this->listings as $listing) {
            $bookable[$listing['id']] = $listing['published'];
        }

        $nextMonth = $this->today->addMonthNoOverflow()->startOfMonth();

        foreach ($this->spaceRefs as $space) {
            if (! $bookable[$space['property']]) {
                continue;
            }

            if ($space['special']) {
                // The "last bed" room: beds A-C confirmed from next month's 1st, bed D left free.
                if ($space['label'] !== 'Bed D') {
                    $this->addReservation($space, ReservationStatus::Confirmed, $nextMonth, $nextMonth->addMonthsNoOverflow(6));
                }

                continue;
            }

            foreach ($scheduler->chain($from, $lastMoveIn) as $stay) {
                $this->addReservation($space, $this->statusFor($stay['move_in'], $stay['move_out']), $stay['move_in'], $stay['move_out']);
            }

            // Requests that never blocked anything may overlap anything.
            foreach (range(1, $this->pick([1, 2, 2, 3, 4, 5])) as $unused) {
                $this->addNonBlocking($space);
            }
        }
    }

    private function statusFor(CarbonImmutable $moveIn, CarbonImmutable $moveOut): ReservationStatus
    {
        if ($moveOut <= $this->today) {
            return ReservationStatus::Ended;
        }
        if ($moveIn <= $this->today) {
            return ReservationStatus::Active;
        }

        return $this->weighted([
            ReservationStatus::Confirmed->value => 55,
            ReservationStatus::ProofUploaded->value => 30,
            ReservationStatus::Held->value => 15,
        ], ReservationStatus::class);
    }

    /**
     * @param  array{id: int, room: int, account: int, property: int, label: string, fee: int, special: bool}  $space
     */
    private function addNonBlocking(array $space): void
    {
        $kind = $this->weighted([
            ReservationStatus::Declined->value => 27,
            ReservationStatus::Cancelled->value => 37,
            ReservationStatus::Expired->value => 30,
            'expired_hold' => 6,
        ]);

        $moveIn = $kind === 'expired_hold'
            ? $this->today->addDays($this->int(0, 75))
            : $this->today->addDays($this->int(-540, 60));
        $moveOut = $moveIn->addMonthsNoOverflow($this->pick([1, 3, 6, 6, 12]));

        if ($kind === 'expired_hold') {
            $this->addReservation($space, ReservationStatus::Held, $moveIn, $moveOut, expiredHold: true);

            return;
        }

        $this->addReservation($space, ReservationStatus::from($kind), $moveIn, $moveOut);
    }

    /**
     * @param  array{id: int, room: int, account: int, property: int, label: string, fee: int, special: bool}  $space
     */
    private function addReservation(array $space, ReservationStatus $status, CarbonImmutable $moveIn, CarbonImmutable $moveOut, bool $expiredHold = false): void
    {
        $blocking = $status->isBlockingStatus();
        $liveHold = $status === ReservationStatus::Held && ! $expiredHold;
        $open = $liveHold || $status === ReservationStatus::ProofUploaded;
        $in = $moveIn->toDateString();
        $out = $moveOut->toDateString();

        $renter = $this->pickRenter($space['property'], $in, $out, $blocking || $liveHold, $open);
        if ($renter === null) {
            return;
        }

        $id = $this->nextId('reservations');
        $fee = $space['fee'];
        $created = $this->createdAt($moveIn);
        $hold = null;
        $rejections = 0;
        $decidedBy = $decidedAt = $reason = $cancelledBy = $cancelledAt = $refunded = $note = null;
        $proofs = [];
        $caretaker = $this->accountCaretaker($space['account']);
        $decider = $caretaker !== null && ! $this->chance(70) ? $caretaker : $this->accountOwner($space['account']);

        switch (true) {
            case $liveHold:
                $hold = $this->now->addHours($this->int(1, 23));
                $created = $hold->subDay();
                $rejections = $this->chance(20) ? 1 : 0;
                if ($rejections === 1) {
                    $proofs[] = $this->proofRow($created->addHour(), PaymentProofStatus::Rejected, $fee - 10000, $decider, $created->addHours(5), 'The amount does not match the reservation fee.');
                }
                break;

            case $expiredHold:
                $hold = $this->now->subHours($this->int(1, 72));
                $created = $hold->subDay();
                break;

            case $status === ReservationStatus::Expired:
                $created = $this->createdAt($this->today->addDays($this->int(-540, -3)));
                $hold = $created->addDay();
                break;

            case $status === ReservationStatus::ProofUploaded:
                $rejections = $this->chance(15) ? 1 : 0;
                if ($rejections === 1) {
                    $proofs[] = $this->proofRow($created->addHour(), PaymentProofStatus::Rejected, $fee - 10000, $decider, $created->addHours(3), 'The reference number is not readable.');
                }
                $proofs[] = $this->proofRow($created->addHours(4), PaymentProofStatus::Pending, $fee);
                break;

            case $status === ReservationStatus::Declined:
                $proofs[] = $this->proofRow($created->addHours(2), PaymentProofStatus::Pending, $fee);
                $decidedBy = $decider;
                $decidedAt = $created->addHours($this->int(4, 20));
                $reason = $this->pick(['The room is no longer available.', 'The payment could not be matched.', 'The details did not match the house rules.']);
                $refunded = $this->chance(70);
                $note = $refunded ? 'Refunded by bank transfer.' : null;
                break;

            case $status === ReservationStatus::Cancelled:
                $withProof = $this->chance(60);
                if ($withProof) {
                    $proofs[] = $this->proofRow($created->addHours(2), PaymentProofStatus::Pending, $fee);
                    $refunded = $this->chance(50);
                    $note = $refunded ? 'Refunded by bank transfer.' : 'Cancelled after the refund window.';
                }
                $byOwner = $withProof && $this->chance(25);
                $cancelledBy = $byOwner ? $decider : $renter;
                $cancelledAt = $created->addHours($this->int(6, 30));
                $reason = $byOwner ? 'The room is needed for repairs.' : null;
                break;

            default: // confirmed, active, ended
                $proofs[] = $this->proofRow($created->addHours(2), PaymentProofStatus::Accepted, $fee, $decider, $created->addHours(5));
                $decidedBy = $decider;
                $decidedAt = $created->addHours($this->int(3, 6));
        }

        $this->rows['reservations'][] = [
            'id' => $id, 'space_id' => $space['id'], 'landlord_account_id' => $space['account'], 'renter_id' => $renter,
            'move_in' => $in, 'move_out' => $out, 'status' => $status->value, 'reservation_fee' => $fee,
            'hold_expires_at' => $hold === null ? null : $this->ts($hold),
            'proof_rejections' => $rejections,
            'decided_by' => $decidedBy, 'decided_at' => $decidedAt === null ? null : $this->ts($decidedAt),
            'decision_reason' => $reason,
            'cancelled_by' => $cancelledBy, 'cancelled_at' => $cancelledAt === null ? null : $this->ts($cancelledAt),
            'fee_refunded' => $refunded, 'refund_note' => $note,
            'created_at' => $this->ts($created), 'updated_at' => $this->ts($decidedAt ?? $cancelledAt ?? $created),
        ];

        foreach ($proofs as $proof) {
            $this->rows['payment_proofs'][] = ['id' => $this->nextId('payment_proofs'), 'reservation_id' => $id, 'landlord_account_id' => $space['account']] + $proof;
        }

        if ($blocking || $liveHold) {
            $this->renterStays[$renter][] = [$in, $out];
        }
        if ($open) {
            $this->renterOpen[$renter][] = $space['property'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function proofRow(CarbonImmutable $uploaded, PaymentProofStatus $status, int $amount, ?int $reviewer = null, ?CarbonImmutable $reviewed = null, ?string $reason = null): array
    {
        return [
            'path' => 'proofs/demo/placeholder-'.$this->int(1, 9).'.jpg',
            'mime_type' => 'image/jpeg',
            'amount' => max(0, $amount),
            'reference_no' => sprintf('%06d%06d', $this->int(0, 999999), $this->int(0, 999999)),
            'status' => $status->value,
            'reviewed_by' => $status === PaymentProofStatus::Pending ? null : $reviewer,
            'reviewed_at' => $status === PaymentProofStatus::Pending || $reviewed === null ? null : $this->ts($reviewed),
            'rejection_reason' => $reason,
            'uploaded_at' => $this->ts($uploaded),
        ];
    }

    /** A moment before `$moveIn` (and always before now), during the working day. */
    private function createdAt(CarbonImmutable $moveIn): CarbonImmutable
    {
        $created = $moveIn->subDays($this->int(3, 45));
        $cap = $this->today->subDays($this->int(1, 20));

        return ($created < $cap ? $created : $cap)->setTime($this->int(8, 16), $this->int(0, 59));
    }

    /**
     * A renter who has no blocking stay overlapping these dates (people don't
     * live in two places at once) and, for open reservations, fewer than two
     * open ones and none at this property. Null when nobody fits.
     */
    private function pickRenter(int $propertyId, string $in, string $out, bool $blocking, bool $open): ?int
    {
        foreach (range(1, 40) as $unused) {
            $renter = $this->renterIds[$this->int(0, count($this->renterIds) - 1)];

            if ($open && (count($this->renterOpen[$renter] ?? []) >= 2 || in_array($propertyId, $this->renterOpen[$renter] ?? [], true))) {
                continue;
            }
            if ($blocking) {
                foreach ($this->renterStays[$renter] ?? [] as [$otherIn, $otherOut]) {
                    if (StayScheduler::overlaps($in, $out, $otherIn, $otherOut)) {
                        continue 2;
                    }
                }
            }

            return $renter;
        }

        return null;
    }

    private function accountOwner(int $accountId): int
    {
        foreach ($this->accounts as $account) {
            if ($account['id'] === $accountId) {
                return $account['owner'];
            }
        }

        throw new RuntimeException("Unknown account {$accountId}.");
    }

    private function accountCaretaker(int $accountId): ?int
    {
        foreach ($this->accounts as $account) {
            if ($account['id'] === $accountId) {
                return $account['caretaker'];
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ photos

    /**
     * 3 to 6 ready photos for each published property: the front first (the cover), then rooms
     * that fit its kind, all in one colourway. Called after everything else, so the photos don't
     * shift the random sequence the rest of the data is built from.
     */
    private function buildPhotos(): void
    {
        foreach ($this->listings as $listing) {
            if (! $listing['published']) {
                continue;
            }

            $rooms = $listing['kind'] === PropertyKind::BoardingHouse->value
                ? ['bunks', ...$this->shuffled(['bedroom', 'kitchen', 'bathroom', 'desk'])]
                : ['bedroom', ...$this->shuffled(['kitchen', 'bathroom', 'desk'])];
            $scenes = ['front', ...array_slice($rooms, 0, $this->int(2, min(5, count($rooms))))];
            $palette = $this->pick(array_keys(DemoPhotoPainter::PALETTES));

            foreach ($scenes as $index => $scene) {
                $path = 'properties/'.$this->faker->uuid();
                $this->rows['property_photos'][] = [
                    'id' => $this->nextId('property_photos'), 'property_id' => $listing['id'], 'landlord_account_id' => $listing['account'],
                    'path' => $path,
                    'alt' => str_replace(':property', $listing['name'], DemoPhotoPainter::SCENES[$scene]),
                    'width' => DemoPhotoPainter::WIDTH, 'height' => DemoPhotoPainter::HEIGHT,
                    'sort' => $index + 1, 'status' => PhotoStatus::Ready->value,
                    'created_at' => $this->ts($listing['created']), 'updated_at' => $this->ts($listing['created']),
                ];
                $this->photoFiles[] = ['path' => $path, 'scene' => $scene, 'palette' => $palette];
            }
        }
    }

    // ---------------------------------------------------------------- viewings

    private const VIEWING_MESSAGES = [
        'Hi! Is the room still available? I can come after work.',
        'I would like to see the room and the shared bathroom.',
        'Can my sister come along? We might share a room.',
        'Is parking available for a motorbike?',
        'I am a nursing student and would like to see the study area.',
        'Hello, I am moving to the city next month for a new job.',
        'Is it near a jeepney route? Thank you!',
        'Looking forward to seeing the place.',
    ];

    private const VIEWING_NOTES = [
        'Ring the bell at the green gate and ask for the caretaker.',
        'Please bring a valid ID. See you there!',
        'The room is on the second floor. Parking is on the street.',
        'Sorry, we have a family event that day. Please pick another time.',
        'This room was just taken. Please check our other rooms.',
        'See you then. Call this number when you arrive.',
    ];

    /** @var array<int, list<int>> renter id => property ids of their open viewing requests */
    private array $viewingOpen = [];

    /**
     * Viewing requests: the scripted set the demo accounts show, then random ones across the pool. `$this->today`
     * is Manila midnight, so every time is a real slot (8 AM to 6 PM, on the half hour). Open requests (requested
     * or accepted, in the future) respect the limits of 3 per renter and 1 per property.
     */
    private function buildViewings(): void
    {
        $byEmail = [];
        foreach ($this->rows['users'] as $user) {
            $byEmail[self::toString($user['email'])] = self::toInt($user['id']);
        }

        $ownerAccount = $this->accounts[0]['id'];
        $published = array_values(array_filter($this->listings, fn (array $listing): bool => $listing['published']));
        $ownersProperties = array_values(array_map(fn (array $listing): int => $listing['id'], array_filter($published, fn (array $listing): bool => $listing['account'] === $ownerAccount)));

        if (count($ownersProperties) >= 5) {
            [$renter1, $renter2] = [$byEmail[self::RENTER1_EMAIL], $byEmail[self::RENTER2_EMAIL]];
            [$p0, $p1, $p2, $p3, $p4] = $ownersProperties;
            // The earliest open slot today, or 8 AM tomorrow once none is left: shows up first, marked "Today", on the landlord's list.
            $same = ViewingSlots::earliest();

            $scripted = [
                // renter1: two open (one left for the demo), and a past one in each of the other states.
                [$renter1, $p0, ViewingRequestStatus::Accepted, $this->today->addDay()->setTime(10, 0), self::VIEWING_MESSAGES[0], self::VIEWING_NOTES[0]],
                [$renter1, $p1, ViewingRequestStatus::Requested, $this->today->addDays(3)->setTime(14, 30), self::VIEWING_MESSAGES[2], null],
                [$renter1, $p2, ViewingRequestStatus::Done, $this->today->subDays(5)->setTime(15, 0), null, self::VIEWING_NOTES[1]],
                [$renter1, $p3, ViewingRequestStatus::Declined, $this->today->addDays(2)->setTime(9, 0), self::VIEWING_MESSAGES[1], self::VIEWING_NOTES[3]],
                [$renter1, $p4, ViewingRequestStatus::Cancelled, $this->today->addDays(6)->setTime(16, 0), null, null],
                // renter2: one same-day request waiting, one accepted, and two past.
                [$renter2, $p0, ViewingRequestStatus::Requested, $same, self::VIEWING_MESSAGES[4], null],
                [$renter2, $p1, ViewingRequestStatus::Accepted, $this->today->addDays(4)->setTime(11, 0), null, self::VIEWING_NOTES[2]],
                [$renter2, $p2, ViewingRequestStatus::Declined, $this->today->subDays(2)->setTime(9, 30), self::VIEWING_MESSAGES[5], self::VIEWING_NOTES[4]],
                [$renter2, $p3, ViewingRequestStatus::Done, $this->today->subDays(12)->setTime(13, 0), self::VIEWING_MESSAGES[6], null],
            ];

            foreach ($scripted as [$renter, $property, $status, $at, $message, $note]) {
                $this->addViewing($renter, $property, $status, $at, $message, $note);
            }
        }

        // The pool. A renter never goes over the limits for an open request: the draw is skipped instead.
        foreach (range(1, 150) as $unused) {
            $listing = $this->pick($published);
            $renter = $this->pick($this->renterIds);
            $future = $this->chance(60);
            $slots = ViewingSlots::times();
            [$hour, $minute] = array_map(intval(...), explode(':', $this->pick($slots)));
            $at = $future
                ? $this->today->addDays($this->int(1, 28))->setTime($hour, $minute)
                : $this->today->subDays($this->int(1, 40))->setTime($hour, $minute);
            $status = $future
                ? $this->weighted([ViewingRequestStatus::Requested->value => 45, ViewingRequestStatus::Accepted->value => 35, ViewingRequestStatus::Declined->value => 10, ViewingRequestStatus::Cancelled->value => 10], ViewingRequestStatus::class)
                : $this->weighted([ViewingRequestStatus::Done->value => 45, ViewingRequestStatus::Declined->value => 15, ViewingRequestStatus::Cancelled->value => 15, ViewingRequestStatus::Accepted->value => 10, ViewingRequestStatus::Requested->value => 15], ViewingRequestStatus::class);
            $message = $this->chance(60) ? $this->pick(self::VIEWING_MESSAGES) : null;
            $note = in_array($status, [ViewingRequestStatus::Accepted, ViewingRequestStatus::Declined], true) && $this->chance(50) ? $this->pick(self::VIEWING_NOTES) : null;

            if ($this->viewingIsOpen($status, $at)) {
                $open = $this->viewingOpen[$renter] ?? [];

                if (count($open) >= 3 || in_array($listing['id'], $open, true)) {
                    continue;
                }
            }

            $this->addViewing($renter, $listing['id'], $status, $at, $message, $note);
        }
    }

    private function viewingIsOpen(ViewingRequestStatus $status, CarbonImmutable $at): bool
    {
        return in_array($status, [ViewingRequestStatus::Requested, ViewingRequestStatus::Accepted], true) && $at->greaterThan($this->now);
    }

    private function addViewing(int $renter, int $propertyId, ViewingRequestStatus $status, CarbonImmutable $at, ?string $message, ?string $note): void
    {
        $matching = array_values(array_filter($this->listings, fn (array $candidate): bool => $candidate['id'] === $propertyId));
        $listing = $matching[0] ?? throw new RuntimeException("Unknown property {$propertyId}.");

        if ($this->viewingIsOpen($status, $at)) {
            $this->viewingOpen[$renter][] = $propertyId;
        }

        $answered = in_array($status, [ViewingRequestStatus::Accepted, ViewingRequestStatus::Declined, ViewingRequestStatus::Done], true);
        $decider = $answered ? ($this->accountCaretaker($listing['account']) ?? $this->accountOwner($listing['account'])) : null;
        $created = $at->subDays($this->int(1, 6))->setTime($this->int(8, 20), 0);
        $created = $created->greaterThan($this->now) ? $this->now->subHour() : $created;

        $this->rows['viewing_requests'][] = [
            'id' => $this->nextId('viewing_requests'), 'property_id' => $propertyId, 'landlord_account_id' => $listing['account'],
            'renter_id' => $renter, 'preferred_at' => $this->ts($at), 'message' => $message, 'status' => $status->value,
            'landlord_note' => $answered ? $note : null, 'decided_by' => $decider,
            'created_at' => $this->ts($created), 'updated_at' => $this->ts($answered ? $created->addHours(3) : $created),
        ];
    }

    /** Each scene and colourway is painted and resized once, then written under every photo's own path. */
    private function writePhotoFiles(): void
    {
        $disk = Storage::disk(PropertyPhoto::DISK);
        $painter = new DemoPhotoPainter;
        $processor = new PhotoProcessor;

        foreach ($this->photoFiles as $file) {
            $painting = self::$paintings["{$file['scene']}/{$file['palette']}"] ??= $processor->process($painter->paint($file['scene'], $file['palette']));

            $disk->put("{$file['path']}-thumb.webp", $painting->thumb, PropertyPhoto::WRITE_OPTIONS);
            $disk->put("{$file['path']}-full.webp", $painting->full, PropertyPhoto::WRITE_OPTIONS);
        }
    }

    /**
     * The demo proofs point at nine placeholder receipts (slice 5.3), painted with GD so the
     * landlord's proof viewer has something to show. Nothing random: the rows don't change.
     */
    private function writeProofFiles(): void
    {
        $disk = Storage::disk(PaymentProof::DISK);
        $painter = new DemoReceiptPainter;

        foreach (range(1, 9) as $number) {
            $disk->put("proofs/demo/placeholder-{$number}.jpg", $painter->paint($number));
        }
    }

    // --------------------------------------------------------------- plumbing

    private function flush(): void
    {
        foreach (['users', 'landlord_accounts', 'landlord_members', 'properties', 'property_amenity', 'rooms', 'spaces', 'reservations', 'payment_proofs', 'property_photos', 'viewing_requests'] as $table) {
            foreach (array_chunk($this->rows[$table], self::CHUNK) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
    }

    private function nextId(string $table): int
    {
        return ++$this->lastId[$table];
    }

    private function ts(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Y-m-d H:i:s');
    }

    private function uniqueSlug(string $slug): string
    {
        $candidate = $slug;
        for ($n = 2; isset($this->usedSlugs[$candidate]); $n++) {
            $candidate = $slug.'-'.$n;
        }
        $this->usedSlugs[$candidate] = true;

        return $candidate;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new RuntimeException('Expected a number.');
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : throw new RuntimeException('Expected a string.');
    }

    private function int(int $min, int $max): int
    {
        return $this->faker->numberBetween($min, $max);
    }

    private function chance(int $percent): bool
    {
        return $this->int(1, 100) <= $percent;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private function shuffled(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_values($items);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  array<string, int>  $weights
     * @param  class-string<T>|null  $enum
     * @return ($enum is null ? string : T)
     */
    private function weighted(array $weights, ?string $enum = null): mixed
    {
        $roll = $this->int(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $enum === null ? (string) $value : $enum::from((string) $value);
            }
        }

        throw new RuntimeException('Unreachable.');
    }
}
