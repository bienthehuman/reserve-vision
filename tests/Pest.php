<?php

declare(strict_types=1);

use App\Actions\ReserveSpace;
use App\Enums\CityKind;
use App\Enums\LandlordRole;
use App\Models\Barangay;
use App\Models\City;
use App\Models\LandlordAccount;
use App\Models\LandlordMember;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\Province;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Space;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\Places\PlacesImporter;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concurrency\ConcurrencyTestCase;
use Tests\TestCase;

/*
| Feature tests boot the app and wrap each test in a transaction on the default
| (`app`) connection. Never RefreshDatabase: `app` can't run DDL (ADR 0008);
| TestCase migrates once per run as `migrator` instead.
*/
pest()->extend(TestCase::class)
    ->use(DatabaseTransactions::class)
    ->in('Feature');

/*
| The driver error code of the QueryException a closure throws (0 if none).
| Tests assert on codes, not messages: MySQL and MariaDB word errors differently.
|
|   1062  duplicate key             (both)
|   1452  foreign key violation     (both)
|   1264  value out of range        (both; e.g. a negative into an unsigned column)
|   1644  SIGNAL SQLSTATE '45000'   (both; the audit_logs triggers)
|   1142  privilege denied          (both)
|   3819  CHECK violated            (MySQL)
|   4025  CHECK violated            (MariaDB)
*/
function dbErrorCode(Closure $statement): int
{
    try {
        $statement();
    } catch (QueryException $e) {
        $code = $e->errorInfo[1] ?? 0;

        return is_int($code) ? $code : 0;
    }

    return 0;
}

/** The error codes MySQL and MariaDB give a violated CHECK constraint. */
const CHECK_VIOLATION = [3819, 4025];

/*
| Reservations (tests/Feature/Reservations). "Now" is frozen by each test file.
*/

/** A private room (one "Whole room" space) in a published property of a verified account. */
function reservableRoom(?Property $property = null): Room
{
    return Room::factory()
        ->for($property ?? Property::factory()->published()->create())
        ->create();
}

/** ReserveSpace for a "Y-m-d" move-in date. */
function reserve(User $renter, Room $room, string $moveIn, int $months = 6): Reservation
{
    return app(ReserveSpace::class)->handle($renter, $room, CarbonImmutable::parse($moveIn), $months);
}

/** The single space of a private room. */
function onlySpace(Room $room): Space
{
    return $room->spaces()->sole();
}

/*
| Auth (tests/Feature/Auth).
*/

/** A landlord user with a membership: owner of a verified account unless told otherwise. */
function landlordUser(bool $verifiedAccount = true, LandlordRole $role = LandlordRole::Owner, bool $verifiedEmail = true): User
{
    $account = LandlordAccount::factory()->state($verifiedAccount ? ['is_verified' => true, 'verified_at' => now()] : [])->create();
    $user = User::factory()->landlord()->state($verifiedEmail ? [] : ['email_verified_at' => null])->create();
    LandlordMember::factory()->for($account)->for($user)->state(['role' => $role])->create();

    return $user;
}

/** A landlord user (owner unless told otherwise) in an account that already exists. */
function landlordUserOf(LandlordAccount|int $account, LandlordRole $role = LandlordRole::Owner): User
{
    $user = User::factory()->landlord()->create();
    LandlordMember::factory()->for($account instanceof LandlordAccount ? $account : LandlordAccount::query()->findOrFail($account))
        ->for($user)->state(['role' => $role])->create();

    return $user;
}

/** The id of the landlord account a landlord user belongs to. */
function accountIdOf(User $user): int
{
    return $user->landlordMember()->sole()->landlord_account_id;
}

/**
 * The audit rows, oldest first, read around the isolation scope (the test is
 * the system here, whoever is logged in).
 *
 * @return Collection<int, stdClass>
 */
function auditRows(?string $action = null): Collection
{
    return DB::table('audit_logs')
        ->when($action !== null, fn ($query) => $query->where('action', $action))
        ->orderBy('id')
        ->get();
}

/**
 * A valid sign-up form; override any field.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationForm(array $overrides = []): array
{
    return [
        'type' => 'renter',
        'first_name' => 'Maria Cristina',
        'middle_name' => '',
        'last_name' => 'Dela Cruz',
        'suffix' => null,
        'email' => 'maria@example.com',
        'phone' => '0917 123 4567',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
        ...$overrides,
    ];
}

/*
| Concurrency suite (tests/Concurrency, `composer test:concurrency`): real
| parallel HTTP against a running server, fixtures committed, no transaction.
| Not part of `composer test`: phpunit.xml doesn't list the directory.
*/
pest()->extend(ConcurrencyTestCase::class)->in('Concurrency');

/*
| Landlord editing (tests/Feature/Landlord, slice 4.1).
*/

/*
| Places (slice 4.4). Tests don't import the country: they build the rows they need.
*/

/** The demo cities' real names and provinces, and a real barangay of each (Manila's with its district). */
const TEST_PLACES = [
    'manila' => ['Manila', 'Metro Manila', 'Barangay 395', 'Sampaloc'],
    'quezon-city' => ['Quezon City', 'Metro Manila', 'Loyola Heights', null],
    'makati' => ['Makati', 'Metro Manila', 'Poblacion', null],
    'cebu-city' => ['Cebu City', 'Cebu', 'Lahug', null],
    'davao-city' => ['Davao City', 'Davao del Sur', 'Matina Crossing', null],
    'baguio' => ['Baguio', 'Benguet', 'Irisan', null],
    'iloilo-city' => ['Iloilo City', 'Iloilo', 'Tabuc Suba (Jaro)', null],
];

/**
 * A barangay in one of TEST_PLACES' cities, creating the province and city on first use in the test.
 * `$barangay` names another barangay of that city (created if it isn't there yet).
 */
function placeIn(string $citySlug = 'manila', ?string $barangay = null): Barangay
{
    [$cityName, $provinceName, $defaultBarangay, $district] = TEST_PLACES[$citySlug] ?? throw new InvalidArgumentException("No test place [{$citySlug}].");
    $province = Province::query()->where('slug', Str::slug($provinceName))->first()
        ?? Province::factory()->create(['name' => $provinceName, 'slug' => Str::slug($provinceName)]);
    $city = City::query()->where('slug', $citySlug)->first()
        ?? City::factory()->for($province)->create(['name' => $cityName, 'slug' => $citySlug, 'kind' => CityKind::City]);
    $name = $barangay ?? $defaultBarangay;

    return Barangay::query()->where('city_id', $city->id)->where('name', $name)->first()
        ?? Barangay::factory()->for($city)->create(['name' => $name, 'district' => $barangay === null ? $district : null]);
}

/**
 * The demo cities' rows of the real PSGC files, imported: DemoSeeder needs their real barangays,
 * and the whole country takes seconds. The files are filtered once per process into a temp directory.
 */
function importDemoPlaces(): void
{
    static $directory = null;

    if ($directory === null) {
        $target = sys_get_temp_dir().'/rv-demo-places-'.getmypid();
        is_dir($target) || mkdir($target);
        /** @var list<array{slug: string, rate: float}> $demo */
        $demo = require database_path('data/places.php');
        $codes = array_flip(array_map(fn (array $city): string => (string) array_search($city['slug'], PlacesImporter::PINNED, true)
            ?: throw new RuntimeException("Demo city [{$city['slug']}] isn't pinned."), $demo));
        $provinces = [];
        $filter = function (string $file, callable $keep) use ($target): void {
            $lines = file(database_path("data/psgc/{$file}")) ?: [];
            $kept = [$lines[0]];
            foreach (array_slice($lines, 1) as $line) {
                if ($keep(str_getcsv($line, escape: ''))) {
                    $kept[] = $line;
                }
            }
            file_put_contents("{$target}/{$file}", implode('', $kept));
        };
        $filter('cities.csv', function (array $row) use ($codes, &$provinces): bool {
            if (! isset($codes[$row[0]])) {
                return false;
            }
            $provinces[$row[1]] = true;

            return true;
        });
        $filter('provinces.csv', fn (array $row): bool => isset($provinces[$row[0]]));
        $filter('barangays.csv', fn (array $row): bool => isset($codes[$row[1]]));
        $directory = $target;
    }

    app(PlacesImporter::class)->import($directory);
}

/**
 * The address fields of the property form for a barangay (default: placeIn()).
 *
 * @return array{city_id: int, barangay_id: int}
 */
function placeFields(?Barangay $barangay = null): array
{
    $barangay ??= placeIn();

    return ['city_id' => $barangay->city_id, 'barangay_id' => $barangay->id];
}

/**
 * An owner of a verified account with a published property and a room in it.
 * Create fixtures before actingAs() (CLAUDE.md, quirks in 3.1).
 *
 * @return array{0: User, 1: Property, 2: Room}
 */
function ownerWithRoom(int $beds = 0, string $name = 'Alpha Home'): array
{
    $owner = landlordUser();
    $property = Property::factory()->published()->at(placeIn('manila'))->create([
        'landlord_account_id' => accountIdOf($owner), 'name' => $name, 'slug' => Str::slug($name),
    ]);
    $room = ($beds > 0 ? Room::factory()->shared(beds: $beds) : Room::factory())->for($property)->create(['name' => 'Room 1', 'monthly_rate' => 650000, 'reservation_fee' => 150000]);

    return [$owner, $property, $room];
}

/**
 * A valid property form body, in Loyola Heights, Quezon City; override any field (`...placeFields(placeIn('makati'))` for another place).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function propertyForm(array $overrides = []): array
{
    return [
        'kind' => 'boarding_house', 'name' => 'Santos Bedspace House', ...placeFields(placeIn('quezon-city')),
        'address' => '12 Mabini Street', 'gender_policy' => 'any', 'amenities' => [], 'house_rules' => 'Quiet hours 10 PM.',
        'payment_instructions' => 'GCash 0917 123 4567 (J. Santos).',
        ...$overrides,
    ];
}

/**
 * A valid room form body; override any field.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function roomForm(array $overrides = []): array
{
    return [
        'name' => 'Room 2', 'room_type' => 'private', 'monthly_rate' => '6,500', 'reservation_fee' => '1500.50',
        'deposit_months' => 1, 'advance_months' => 2,
        ...$overrides,
    ];
}

/*
| Photos (tests/Feature/Photos, slice 4.2). Real images, made with GD.
*/

/**
 * The bytes of a real image: the left half red, the right half blue, so a test can tell which way up it is.
 * `$format`: jpeg, png, webp or gif.
 */
function photoBytes(int $width = 1200, int $height = 900, string $format = 'jpeg'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height, (int) imagecolorallocate($image, 220, 30, 30));
    imagefilledrectangle($image, intdiv($width, 2), 0, $width, $height, (int) imagecolorallocate($image, 30, 30, 220));

    ob_start();
    match ($format) {
        'png' => imagepng($image),
        'webp' => imagewebp($image),
        'gif' => imagegif($image),
        default => imagejpeg($image, null, 90),
    };

    return (string) ob_get_clean();
}

/**
 * A JPEG like a phone's: an EXIF block with the orientation (1 to 8) and a GPS position
 * (14° 35' 58" N), written by hand right after the start-of-image marker.
 */
function jpegWithExif(int $orientation, int $width = 1200, int $height = 800): string
{
    $tiff = 'II'.pack('vV', 42, 8)
        // IFD0 at 8: Orientation, and a pointer to the GPS IFD at 38.
        .pack('v', 2)
        .pack('vvVvv', 0x0112, 3, 1, $orientation, 0)
        .pack('vvVV', 0x8825, 4, 1, 38)
        .pack('V', 0)
        // GPS IFD at 38: GPSLatitudeRef "N", GPSLatitude (three rationals at 68).
        .pack('v', 2)
        .pack('vvV', 0x0001, 2, 2)."N\0\0\0"
        .pack('vvVV', 0x0002, 5, 3, 68)
        .pack('V', 0)
        .pack('VVVVVV', 14, 1, 35, 1, 58, 1);
    $exif = "Exif\0\0".$tiff;
    $jpeg = photoBytes($width, $height);

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
}

/** An uploaded file with real image bytes (or any bytes you give it). */
function photoUpload(string $name = 'room.jpg', ?string $bytes = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes ?? photoBytes());
}

/** The colour at a point of an encoded image, as "red", "blue" or "other" (WebP is lossy, so by the dominant channel). */
function photoColourAt(string $bytes, float $x, float $y): string
{
    $image = imagecreatefromstring($bytes);
    $rgb = imagecolorat($image, (int) (imagesx($image) * $x), (int) (imagesy($image) * $y));
    [$red, $blue] = [($rgb >> 16) & 0xFF, $rgb & 0xFF];

    return match (true) {
        $red > 150 && $blue < 100 => 'red',
        $blue > 150 && $red < 100 => 'blue',
        default => 'other',
    };
}

/** Fakes the two media disks, so a test's files never touch storage/app. The photos disk keeps its public URL. */
function fakeMediaDisks(): void
{
    Storage::fake('photos', ['url' => config('filesystems.disks.photos.url'), 'visibility' => 'public']);
    Storage::fake('proofs');
}

/*
| Listing pages (tests/Feature/Listings, slice 5.1).
*/

/**
 * A published Manila property named "Casa Verde" with a private room at ₱6,500 and a 4-bed shared room at ₱3,500,
 * and two ready photos (the cover has an owner's description, the second doesn't). Returns [property, private room, shared room].
 *
 * @return array{0: Property, 1: Room, 2: Room}
 */
function listedProperty(string $name = 'Casa Verde'): array
{
    $property = Property::factory()->published()->at(placeIn('manila'))->state([
        'name' => $name,
        'slug' => Str::slug($name),
        'kind' => 'boarding_house',
        'address' => '12 Lacson Ave',
        'house_rules' => 'No visitors after 10 PM. The reservation fee is refunded if we decline.',
        'payment_instructions' => 'GCash 0917 000 0000, ask for Ate Lorna',
    ])->create();
    $private = Room::factory()->for($property)->create(['name' => 'Corner Room', 'monthly_rate' => 650000, 'reservation_fee' => 150000, 'deposit_months' => 2, 'advance_months' => 1]);
    $shared = Room::factory()->shared(beds: 4)->for($property)->create(['name' => 'Bedspace Room', 'monthly_rate' => 350000]);
    PropertyPhoto::factory()->for($property)->create(['sort' => 1, 'alt' => 'The front gate of Casa Verde']);
    PropertyPhoto::factory()->for($property)->create(['sort' => 2]);

    return [$property, $private, $shared];
}

/*
| Proofs and the renter flow (tests/Feature/Proofs, slice 5.3).
*/

/**
 * The bytes of a small real PDF with `$pages` pages. With `$objectStreams` the page objects are packed
 * into a compressed object stream (PDF 1.5+, as most phone apps and browsers save them), so they can't
 * be seen without inflating it.
 */
function pdfBytes(int $pages = 1, bool $objectStreams = false): string
{
    $kids = implode(' ', array_map(fn (int $i): string => (3 + $i).' 0 R', range(0, $pages - 1)));
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>', "<< /Type /Pages /Kids [{$kids}] /Count {$pages} >>"];

    foreach (range(1, $pages) as $page) {
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>';
    }

    if ($objectStreams) {
        $body = implode("\n", $objects);
        $deflated = (string) gzcompress($body);

        return "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n"
            .'9 0 obj << /Type /ObjStm /N '.count($objects).' /First 0 /Filter /FlateDecode /Length '.strlen($deflated)." >>\nstream\n{$deflated}\nendstream\nendobj\n"
            ."trailer << /Root 1 0 R >>\nstartxref\n0\n%%EOF\n";
    }

    $pdf = "%PDF-1.4\n";

    foreach ($objects as $i => $object) {
        $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
    }

    return $pdf.'trailer << /Root 1 0 R /Size '.(count($objects) + 1)." >>\nstartxref\n0\n%%EOF\n";
}

/** An uploaded proof file: a real PNG receipt unless you give other bytes. */
function proofUpload(string $name = 'receipt.png', ?string $bytes = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes ?? photoBytes(400, 700, 'png'));
}

/** A live hold of `$renter` on a fresh reservable room (fee ₱1,500), through ReserveSpace. */
function liveHold(User $renter, ?Room $room = null): Reservation
{
    $room ??= Room::factory()->for(Property::factory()->published())->create(['reservation_fee' => 150000]);

    return reserve($renter, $room, BusinessDate::today()->addDays(14)->toDateString());
}

/** A valid proof form for `liveHold()`'s fee. */
function proofForm(array $overrides = []): array
{
    return [...['proof' => proofUpload(), 'amount' => '1500', 'reference_no' => '1009 482 771'], ...$overrides];
}
