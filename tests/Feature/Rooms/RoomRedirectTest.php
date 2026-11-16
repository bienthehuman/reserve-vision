<?php

declare(strict_types=1);

use App\Models\LandlordAccount;
use App\Models\Property;
use App\Models\Room;

/*
| The 2.5 room page became an anchor on the listing page (slice 5.1).
*/

it('redirects permanently to the room on its listing page, keeping the stay', function (): void {
    $room = reservableRoom(Property::factory()->published()->at(placeIn('manila'))->create(['name' => 'Casa Verde', 'slug' => 'casa-verde']));

    $this->get("/rooms/{$room->id}")->assertStatus(301)->assertRedirect("/listings/manila/casa-verde#room-{$room->id}");
    $this->get("/rooms/{$room->id}?move_in=2027-04-01&months=6&junk=1")->assertStatus(301)
        ->assertRedirect("/listings/manila/casa-verde?move_in=2027-04-01&months=6#room-{$room->id}");
});

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
