<?php

declare(strict_types=1);

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
use Illuminate\Support\Facades\DB;

// The migrations hard-code their enum values (they are frozen history); this keeps
// them equal to the PHP enums. Change an enum without a migration and this fails.

/** @return array<string, array{string, class-string<BackedEnum>}> */
function enumChecks(): array
{
    return [
        'users.suffix' => ['users_suffix_valid', NameSuffix::class],
        'users.type' => ['users_type_valid', UserType::class],
        'landlord_members.role' => ['landlord_members_role_valid', LandlordRole::class],
        'landlord_invitations.role' => ['landlord_invitations_role_valid', LandlordRole::class],
        'properties.kind' => ['properties_kind_valid', PropertyKind::class],
        'properties.gender_policy' => ['properties_gender_policy_valid', GenderPolicy::class],
        'property_photos.status' => ['property_photos_status_valid', PhotoStatus::class],
        'rooms.room_type' => ['rooms_room_type_valid', RoomType::class],
        'reservations.status' => ['reservations_status_valid', ReservationStatus::class],
        'payment_proofs.status' => ['payment_proofs_status_valid', PaymentProofStatus::class],
        'viewing_requests.status' => ['viewing_requests_status_valid', ViewingRequestStatus::class],
    ];
}

/** @return list<string> */
function checkConstraintValues(string $constraint): array
{
    $row = DB::selectOne(
        'SELECT CHECK_CLAUSE AS clause FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
        [$constraint],
    );

    preg_match_all("/'([a-z_]+)\\\\?'/", $row->clause ?? '', $matches);

    sort($matches[1]);

    return $matches[1];
}

it('has a CHECK whose values equal the enum', function (string $constraint, string $enum): void {
    $expected = array_map(fn (UnitEnum $case): string => (string) $case->value, $enum::cases());
    sort($expected);

    expect(checkConstraintValues($constraint))->toBe($expected);
})->with(enumChecks(...));

it('has no enum CHECK that is not covered above', function (): void {
    $covered = array_column(enumChecks(), 0);
    sort($covered);

    $all = collect(DB::select(
        "SELECT CONSTRAINT_NAME AS name FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME LIKE '%\\_valid'"
    ))->pluck('name')->sort()->values()->all();

    expect($all)->toBe($covered);
});
