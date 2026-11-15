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
