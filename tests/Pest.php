<?php

declare(strict_types=1);

use App\Actions\ReserveSpace;
use App\Enums\LandlordRole;
use App\Models\LandlordAccount;
use App\Models\LandlordMember;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

/**
 * An owner of a verified account with a published property and a room in it.
 * Create fixtures before actingAs() (CLAUDE.md, quirks in 3.1).
 *
 * @return array{0: User, 1: Property, 2: Room}
 */
function ownerWithRoom(int $beds = 0, string $name = 'Alpha Home'): array
{
    $owner = landlordUser();
    $property = Property::factory()->published()->create([
        'landlord_account_id' => accountIdOf($owner), 'name' => $name, 'city' => 'Manila', 'city_slug' => 'manila', 'slug' => Str::slug($name),
    ]);
    $room = ($beds > 0 ? Room::factory()->shared(beds: $beds) : Room::factory())->for($property)->create(['name' => 'Room 1', 'monthly_rate' => 650000, 'reservation_fee' => 150000]);

    return [$owner, $property, $room];
}

/**
 * A valid property form body; override any field.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function propertyForm(array $overrides = []): array
{
    return [
        'kind' => 'boarding_house', 'name' => 'Santos Bedspace House', 'city' => 'quezon-city', 'barangay' => 'Loyola Heights',
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
