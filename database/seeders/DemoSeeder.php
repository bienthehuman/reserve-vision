<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\GenderPolicy;
use App\Enums\LandlordRole;
use App\Enums\NameSuffix;
use App\Enums\PaymentProofStatus;
use App\Enums\PropertyKind;
use App\Enums\ReservationStatus;
use App\Enums\RoomType;
use App\Enums\UserType;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\StayScheduler;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
 * - Refuses to run in production unless APP_DEMO=true.
 *
 * Tables are filled with chunked inserts. Money is integer centavos.
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
            $this->flush();
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

        foreach (['users', 'landlord_accounts', 'landlord_members', 'properties', 'rooms', 'spaces', 'reservations', 'payment_proofs'] as $table) {
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

    /** @var list<array{id: int, account: int, kind: string, published: bool}> */
    private array $listings = [];

    /** @var list<array{id: int, room: int, account: int, property: int, label: string, fee: int, special: bool}> */
    private array $spaceRefs = [];

    private function buildListings(): void
    {
        /** @var list<array{city: string, slug: string, lat: float, lng: float, spread: float, rate: float, barangays: list<string>}> $places */
        $places = require database_path('data/places.php');
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
     * @param  array{id: int, owner: int, caretaker: ?int, verified: bool}  $account
     * @param  array{city: string, slug: string, lat: float, lng: float, spread: float, rate: float, barangays: list<string>}  $place
     * @param  list<int>  $amenityIds
     */
    private function addProperty(array $account, PropertyKind $kind, array $place, array $amenityIds, bool $lastBedHouse): void
    {
        $id = $this->nextId('properties');
        $boarding = $kind === PropertyKind::BoardingHouse;
        $barangay = $lastBedHouse ? 'Sampaloc' : $this->pick($place['barangays']);
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

        $this->rows['properties'][] = [
            'id' => $id, 'landlord_account_id' => $account['id'], 'kind' => $kind->value,
            'name' => $name, 'slug' => $slug, 'city' => $place['city'], 'city_slug' => $place['slug'],
            'barangay' => $barangay,
            'address' => $this->int(1, 480).' '.$this->pick(['Mabini', 'Rizal', 'Bonifacio', 'Luna', 'Del Pilar', 'Mabuhay', 'Sampaguita', 'Narra', 'Acacia', 'Kalayaan']).' Street, Barangay '.$barangay.', '.$place['city'],
            'lat' => number_format($place['lat'] + ($this->int(-1000, 1000) / 1000) * $place['spread'], 6, '.', ''),
            'lng' => number_format($place['lng'] + ($this->int(-1000, 1000) / 1000) * $place['spread'], 6, '.', ''),
            'gender_policy' => $gender,
            'house_rules' => $this->houseRules($boarding),
            'is_published' => $published,
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

        $this->listings[] = ['id' => $id, 'account' => $account['id'], 'kind' => $kind->value, 'published' => $published];
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

    // --------------------------------------------------------------- plumbing

    private function flush(): void
    {
        foreach (['users', 'landlord_accounts', 'landlord_members', 'properties', 'property_amenity', 'rooms', 'spaces', 'reservations', 'payment_proofs'] as $table) {
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
