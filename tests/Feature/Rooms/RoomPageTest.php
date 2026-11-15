<?php

declare(strict_types=1);

use App\Models\LandlordAccount;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

/*
| The minimal room page (slice 2.5). "Now" is 2027-03-15 10:00 Manila.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

it('server-renders the room name, property, rate and free spaces', function (): void {
    $room = Room::factory()->shared(beds: 4)
        ->for(Property::factory()->published()->state(['name' => 'Casa Verde', 'city' => 'Manila', 'barangay' => 'Sampaloc']))
        ->create(['name' => 'Blue Room', 'monthly_rate' => 450000]);
    Reservation::factory()->confirmed()->for($room->spaces()->orderBy('id')->firstOrFail())
        ->create(['move_in' => '2027-04-01', 'move_out' => '2027-10-01']);

    $this->get("/rooms/{$room->id}?move_in=2027-04-01&months=6")
        ->assertOk()
        ->assertSee('<h1 class="h2 mb-2">Blue Room</h1>', false)
        ->assertSee('Casa Verde')
        ->assertSee('₱4,500')
        ->assertSee('3 of 4 beds free')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Rooms/Show')
            ->where('freeSpaces', 3)
            ->where('stay', ['move_in' => '2027-04-01', 'months' => 6, 'move_out' => '2027-10-01'])
            ->where('window.first', '2027-03-15')
            ->where('window.last', '2027-06-13')
            ->where('openReservation', null));
});

it('defaults to moving in today for 6 months and ignores a malformed stay', function (string $query): void {
    $room = reservableRoom();

    $this->get("/rooms/{$room->id}{$query}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stay', ['move_in' => '2027-03-15', 'months' => 6, 'move_out' => '2027-09-15']));
})->with([
    'no query' => [''],
    'bad date and months' => ['?move_in=2027-02-30&months=13'],
    'not a date' => ['?move_in=tomorrow&months=abc'],
]);

it('is a 404 unless the room is listed', function (Closure $makeRoom): void {
    $this->get('/rooms/'.$makeRoom()->id)->assertNotFound();
})->with([
    'archived room' => [fn (): Room => Room::factory()->archived()->for(Property::factory()->published())->create()],
    'unpublished property' => [fn (): Room => Room::factory()->for(Property::factory()->state(['is_published' => false]))->create()],
    'archived property' => [fn (): Room => Room::factory()->for(Property::factory()->published()->state(['archived_at' => now()]))->create()],
    'unverified account' => [function (): Room {
        $room = reservableRoom();
        LandlordAccount::query()->whereKey($room->landlord_account_id)->update(['is_verified' => false, 'verified_at' => null]);

        return $room;
    }],
]);

it('is a 404 for a room that does not exist', function (): void {
    $this->get('/rooms/999999')->assertNotFound();
});

it('points a renter to their own hold in the room instead of offering another', function (): void {
    $room = reservableRoom();
    $renter = User::factory()->create();
    $reservation = reserve($renter, $room, '2027-04-01');

    $this->actingAs($renter)->get("/rooms/{$room->id}")
        ->assertOk()
        ->assertSee('View your hold')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('openReservation', ['id' => $reservation->id, 'space_label' => 'Whole room']));

    $this->actingAs(User::factory()->create())->get("/rooms/{$room->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('openReservation', null));
});

it('tells a landlord that only renters can reserve', function (): void {
    $this->actingAs(landlordUser())->get('/rooms/'.reservableRoom()->id)
        ->assertOk()
        ->assertSee('Only renter accounts can reserve');
});
