<?php

declare(strict_types=1);

use App\Models\LandlordMember;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Space;
use App\Models\User;
use App\Models\ViewingRequest;
use Illuminate\Support\Facades\DB;

// Database invariants (DATA_MODEL.md): they must hold when a bug or a raw query
// bypasses the application. Assertions use driver error codes (see tests/Pest.php).

const FOREIGN_KEY_VIOLATION = 1452;
const DUPLICATE_KEY = 1062;
const OUT_OF_RANGE = 1264;

describe('landlord ownership (composite foreign keys)', function (): void {
    it('rejects a room whose landlord differs from its property', function (): void {
        $property = Property::factory()->create();
        $other = Property::factory()->create();

        expect(dbErrorCode(fn () => Room::factory()->create([
            'property_id' => $property->id,
            'landlord_account_id' => $other->landlord_account_id,
        ])))->toBe(FOREIGN_KEY_VIOLATION);
    });

    it('rejects a space whose landlord differs from its room', function (): void {
        $room = Room::factory()->create();
        $other = Property::factory()->create();

        expect(dbErrorCode(fn () => Space::factory()->create([
            'room_id' => $room->id,
            'landlord_account_id' => $other->landlord_account_id,
        ])))->toBe(FOREIGN_KEY_VIOLATION);
    });

    it('rejects a reservation whose landlord differs from its space', function (): void {
        $space = Space::factory()->create();
        $other = Property::factory()->create();

        expect(dbErrorCode(fn () => Reservation::factory()->create([
            'space_id' => $space->id,
            'landlord_account_id' => $other->landlord_account_id,
        ])))->toBe(FOREIGN_KEY_VIOLATION);
    });

    it('rejects a payment proof, a photo and a viewing request of another landlord', function (): void {
        $reservation = Reservation::factory()->create();
        $other = Property::factory()->create();

        expect(dbErrorCode(fn () => PaymentProof::factory()->create([
            'reservation_id' => $reservation->id,
            'landlord_account_id' => $other->landlord_account_id,
        ])))->toBe(FOREIGN_KEY_VIOLATION);

        $property = Property::factory()->create();

        expect(dbErrorCode(fn () => PropertyPhoto::factory()->create([
            'property_id' => $property->id,
            'landlord_account_id' => $other->landlord_account_id,
        ])))->toBe(FOREIGN_KEY_VIOLATION)
            ->and(dbErrorCode(fn () => ViewingRequest::factory()->create([
                'property_id' => $property->id,
                'landlord_account_id' => $other->landlord_account_id,
            ])))->toBe(FOREIGN_KEY_VIOLATION);
    });

    it('accepts the same chain when the landlord matches, whichever way it is built', function (): void {
        $property = Property::factory()->create();
        $room = Room::factory()->for($property)->create();
        $space = Space::factory()->for($room)->create();
        $reservation = Reservation::factory()->for($space)->create();

        expect($room->landlord_account_id)->toBe($property->landlord_account_id)
            ->and($space->landlord_account_id)->toBe($property->landlord_account_id)
            ->and($reservation->landlord_account_id)->toBe($property->landlord_account_id);
    });
});

describe('CHECK constraints', function (): void {
    it('rejects move_in on or after move_out', function (string $moveIn, string $moveOut): void {
        expect(dbErrorCode(fn () => Reservation::factory()->create([
            'move_in' => $moveIn,
            'move_out' => $moveOut,
        ])))->toBeIn(CHECK_VIOLATION);
    })->with([
        'same day' => ['2027-03-01', '2027-03-01'],
        'reversed' => ['2027-03-02', '2027-03-01'],
    ]);

    it('accepts a stay of one day', function (): void {
        $reservation = Reservation::factory()->create(['move_in' => '2027-03-01', 'move_out' => '2027-03-02']);

        expect($reservation->exists)->toBeTrue();
    });

    it('rejects an unknown status', function (): void {
        $reservation = Reservation::factory()->create();

        expect(dbErrorCode(fn () => DB::table('reservations')
            ->where('id', $reservation->id)->update(['status' => 'bogus'])))->toBeIn(CHECK_VIOLATION);
    });

    it('rejects a negative fee', function (): void {
        $room = Room::factory()->create();

        // Unsigned columns refuse a negative value outright.
        expect(dbErrorCode(fn () => DB::table('rooms')
            ->where('id', $room->id)->update(['reservation_fee' => -1])))->toBe(OUT_OF_RANGE)
            ->and(dbErrorCode(fn () => DB::table('reservations')
                ->insert([...Reservation::factory()->make()->getAttributes(), 'reservation_fee' => -1])))->not->toBe(0);
    });

    it('rejects a monthly rate of zero and deposit or advance beyond three months', function (): void {
        $room = Room::factory()->create();

        foreach (['monthly_rate' => 0, 'deposit_months' => 4, 'advance_months' => 4] as $column => $value) {
            expect(dbErrorCode(fn () => DB::table('rooms')
                ->where('id', $room->id)->update([$column => $value])))->toBeIn(CHECK_VIOLATION);
        }
    });

    it('rejects a third proof rejection', function (): void {
        $reservation = Reservation::factory()->create();

        expect(dbErrorCode(fn () => DB::table('reservations')
            ->where('id', $reservation->id)->update(['proof_rejections' => 3])))->toBeIn(CHECK_VIOLATION);
    });

    it('requires a barangay and a province, but not a street address', function (): void {
        $property = Property::factory()->create();

        DB::table('properties')->where('id', $property->id)->update(['address' => null]);

        foreach (['barangay', 'province'] as $column) {
            expect(dbErrorCode(fn () => DB::table('properties')
                ->where('id', $property->id)->update([$column => null])))->toBe(1048);
        }
    });
});

describe('uniqueness', function (): void {
    it('keeps (city_slug, slug) unique on properties, across landlords', function (): void {
        $first = Property::factory()->create();

        expect(dbErrorCode(fn () => Property::factory()->create([
            'city_slug' => $first->city_slug,
            'slug' => $first->slug,
        ])))->toBe(DUPLICATE_KEY);

        // The same slug in another city is fine.
        expect(Property::factory()->create(['city_slug' => 'elsewhere', 'slug' => $first->slug])->exists)->toBeTrue();
    });

    it('keeps (room_id, label) unique on spaces', function (): void {
        $room = Room::factory()->shared(2)->create();

        expect(dbErrorCode(fn () => Space::factory()->for($room)->create(['label' => 'Bed A'])))->toBe(DUPLICATE_KEY);

        // The same label in another room is fine.
        expect(Space::factory()->create(['label' => 'Bed A'])->exists)->toBeTrue();
    });

    it('lets a landlord user belong to one account only', function (): void {
        $member = LandlordMember::factory()->create();

        expect(dbErrorCode(fn () => LandlordMember::factory()->create(['user_id' => $member->user_id])))->toBe(DUPLICATE_KEY);
    });

    it('keeps user emails unique', function (): void {
        $user = User::factory()->create();

        expect(dbErrorCode(fn () => User::factory()->create(['email' => $user->email])))->toBe(DUPLICATE_KEY);
    });
});
