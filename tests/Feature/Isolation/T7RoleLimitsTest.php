<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Models\LandlordInvitation;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
| T7 (BUILD_PLAN.md): a caretaker tries to change a rate or invite a member, and (4.1) to use
| every other write route of the landlord pages: create or edit a property or room, add or
| rename a space, archive or unarchive any of them, and (4.2) upload, describe, move, re-upload or
| delete a photo, and (4.3) publish or unpublish, send an invitation again or cancel it, and remove
| a member. Expected: 403, nothing changed, no audit
| row, on every one of them, and the edit forms (GET) refuse them too. The owner can do each
| one, and each writes exactly one audit row. (Another account's records are T6: 404, not 403.)
| A test walks the route list, so a write route added later can't be left out.
*/

beforeEach(function (): void {
    fakeMediaDisks();
    Mail::fake();
});

/** An account with an owner, a caretaker and a room at ₱6,500. */
function t7Account(): array
{
    $owner = landlordUser();
    $account = accountIdOf($owner);
    $caretaker = landlordUserOf($account, LandlordRole::Caretaker);
    $room = Room::factory()->for(Property::factory()->published()->state(['landlord_account_id' => $account]))->create(['monthly_rate' => 650000]);

    return [$owner, $caretaker, $room];
}

/** The tables a write could touch, as one comparable value. */
function t7State(): string
{
    return md5((string) json_encode(collect(['properties', 'property_amenity', 'rooms', 'spaces', 'property_photos', 'landlord_invitations', 'landlord_members', 'audit_logs'])
        ->map(fn (string $table) => DB::table($table)->orderBy($table === 'property_amenity' ? 'property_id' : 'id')->get())));
}

/**
 * Every write route of the landlord pages (4.1), by name: method, a path from the account's
 * records, and a valid body, so a 403 can only be the role. Built from t7Account().
 *
 * @return array<string, array{string, string, array<string, mixed>}>
 */
function t7WriteRoutes(array $account): array
{
    [$owner, $caretaker, $room] = $account;
    $property = $room->property;
    // Slice 4.3: an invitation to send again and one to cancel, and the caretaker's membership to remove.
    $resendable = LandlordInvitation::factory()->create(['landlord_account_id' => $property->landlord_account_id, 'invited_by' => $owner->id]);
    $revocable = LandlordInvitation::factory()->create(['landlord_account_id' => $property->landlord_account_id, 'invited_by' => $owner->id]);
    $membership = $caretaker->landlordMember()->sole();
    $beds = Room::factory()->shared(beds: 3)->for($property)->create();
    $space = $beds->spaces()->orderBy('id')->firstOrFail();
    // Photos (4.2): two ready ones, so a move changes something, and a failed one to upload again.
    $photo = PropertyPhoto::factory()->for($property)->create(['sort' => 1]);
    $second = PropertyPhoto::factory()->for($property)->create(['sort' => 2]);
    $failed = PropertyPhoto::factory()->for($property)->failed()->create(['sort' => 3]);
    $room2 = [
        'name' => 'Edited', 'room_type' => 'private', 'monthly_rate' => '7000', 'reservation_fee' => '1000',
        'deposit_months' => 1, 'advance_months' => 1,
    ];

    return [
        'landlord.invitations.store' => ['POST', '/landlord/invitations', ['email' => 'new@example.com']],
        'landlord.properties.store' => ['POST', '/landlord/properties', [
            'kind' => 'apartment', 'name' => 'New Place', ...placeFields($property->linkedBarangay), 'address' => '1 Rizal St', 'gender_policy' => 'any', 'amenities' => [],
        ]],
        'landlord.properties.update' => ['PUT', "/landlord/properties/{$property->id}", [
            'kind' => 'apartment', 'name' => 'Renamed', ...placeFields($property->linkedBarangay), 'address' => '2 Rizal St', 'gender_policy' => 'any', 'amenities' => [],
            'payment_instructions' => 'GCash 0917 123 4567 (J. Santos).', // kept, or the publish below is refused
        ]],
        'landlord.properties.archive' => ['POST', "/landlord/properties/{$property->id}/archive", []],
        'landlord.properties.unarchive' => ['DELETE', "/landlord/properties/{$property->id}/archive", []],
        // After the two above the property is a draft again, with a room and a ready photo: it can be published.
        'landlord.properties.publish' => ['POST', "/landlord/properties/{$property->id}/publish", []],
        'landlord.properties.unpublish' => ['DELETE', "/landlord/properties/{$property->id}/publish", []],
        'landlord.properties.rooms.store' => ['POST', "/landlord/properties/{$property->id}/rooms", $room2],
        'landlord.rooms.update' => ['PUT', "/landlord/rooms/{$room->id}", $room2],
        'landlord.rooms.archive' => ['POST', "/landlord/rooms/{$room->id}/archive", []],
        'landlord.rooms.unarchive' => ['DELETE', "/landlord/rooms/{$room->id}/archive", []],
        'landlord.rooms.spaces.store' => ['POST', "/landlord/rooms/{$beds->id}/spaces", []],
        'landlord.spaces.update' => ['PATCH', "/landlord/spaces/{$space->id}", ['label' => 'Window bed']],
        'landlord.spaces.archive' => ['POST', "/landlord/spaces/{$space->id}/archive", []],
        'landlord.spaces.unarchive' => ['DELETE', "/landlord/spaces/{$space->id}/archive", []],
        'landlord.properties.photos.store' => ['POST', "/landlord/properties/{$property->id}/photos", ['photo' => photoUpload()]],
        'landlord.photos.update' => ['PATCH', "/landlord/photos/{$photo->id}", ['alt' => 'The front gate']],
        'landlord.photos.move' => ['POST', "/landlord/photos/{$photo->id}/move", ['direction' => 'down']],
        'landlord.photos.retry' => ['POST', "/landlord/photos/{$failed->id}/retry", ['photo' => photoUpload()]],
        'landlord.photos.destroy' => ['DELETE', "/landlord/photos/{$second->id}", []],
        'landlord.invitations.resend' => ['POST', "/landlord/invitations/{$resendable->id}/resend", []],
        'landlord.invitations.destroy' => ['DELETE', "/landlord/invitations/{$revocable->id}", []],
        'landlord.members.destroy' => ['DELETE', "/landlord/members/{$membership->id}", []],
    ];
}

it('T7: every write route of the landlord pages is covered here', function (): void {
    $writes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('landlord', $route->gatherMiddleware(), true) && array_diff($route->methods(), ['GET', 'HEAD']) !== [])
        ->map(fn ($route) => $route->getName())->sort()->values()->all();

    expect(array_keys(t7WriteRoutes(t7Account())))->toEqualCanonicalizing($writes);
});

it('T7: a caretaker gets 403 on every write route, with a valid body or an empty one, and nothing changes', function (): void {
    $account = t7Account();
    [, $caretaker] = $account;
    $routes = t7WriteRoutes($account);
    $before = t7State();

    foreach ($routes as $name => [$method, $path, $body]) {
        $this->actingAs($caretaker)->call($method, $path, $body)->assertForbidden("{$name} ({$method} {$path}) should be 403 for a caretaker");
        $this->actingAs($caretaker)->call($method, $path, [])->assertForbidden("{$name} with an empty body should be 403, before validation");
        $this->actingAs($caretaker)->withHeaders(['X-Inertia' => 'true'])->call($method, $path, $body)->assertForbidden();
    }

    expect(t7State())->toBe($before)->and(auditRows())->toBeEmpty()
        ->and(Storage::disk('photos')->allFiles())->toBeEmpty()
        ->and(Storage::disk('proofs')->allFiles())->toBeEmpty();
});

it('T7: a caretaker gets 403 on the edit forms, which the property page doesn\'t link to either', function (): void {
    [, $caretaker, $room] = t7Account();

    foreach (['/landlord/properties/create', "/landlord/properties/{$room->property_id}/edit", "/landlord/properties/{$room->property_id}/rooms/create", "/landlord/rooms/{$room->id}/edit"] as $path) {
        $this->actingAs($caretaker)->get($path)->assertForbidden();
    }

    $this->actingAs($caretaker)->get("/landlord/properties/{$room->property_id}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('property.can_edit', false)->where('property.can_add_room', false)->where('property.can_archive', false)->where('property.can_publish', false)->where('property.can_manage_photos', false)->where('checklist', null)
        ->where('rooms.0.can_edit', false)->where('rooms.0.can_archive', false)->where('rooms.0.can_add_space', false)
        ->where('rooms.0.spaces.0.can_edit', false)->where('rooms.0.spaces.0.can_archive', false));
    $this->actingAs($caretaker)->get('/landlord/properties')->assertOk()->assertInertia(fn ($page) => $page->where('can_create', false));
});

it('T7: a caretaker creating an invitation gets 403 (kept from 3.2: the role, not the route list, is the point)', function (): void {
    [, $caretaker] = t7Account();

    $this->actingAs($caretaker)->postJson('/landlord/invitations', ['email' => 'new@example.com', 'role' => 'owner'])->assertForbidden();

    expect(LandlordInvitation::query()->count())->toBe(0);
});

it('T7: the owner can do each write, with exactly one audit row each', function (): void {
    $account = t7Account();
    [$owner] = $account;
    $routes = t7WriteRoutes($account);

    $expected = [
        'landlord.invitations.store' => 'invitation.created',
        'landlord.properties.store' => 'property.created',
        'landlord.properties.update' => 'property.updated',
        'landlord.properties.archive' => 'property.archived',
        'landlord.properties.unarchive' => 'property.unarchived',
        'landlord.properties.publish' => 'property.published',
        'landlord.properties.unpublish' => 'property.unpublished',
        'landlord.properties.rooms.store' => 'room.created',
        'landlord.rooms.update' => 'room.updated',
        'landlord.rooms.archive' => 'room.archived',
        'landlord.rooms.unarchive' => 'room.unarchived',
        'landlord.rooms.spaces.store' => 'space.added',
        'landlord.spaces.update' => 'space.renamed',
        'landlord.spaces.archive' => 'space.archived',
        'landlord.spaces.unarchive' => 'space.unarchived',
        // The resize job runs right away here (the sync queue) and writes no audit row of its own.
        'landlord.properties.photos.store' => 'photo.uploaded',
        'landlord.photos.update' => 'photo.described',
        'landlord.photos.move' => 'photo.moved',
        'landlord.photos.retry' => 'photo.retried',
        'landlord.photos.destroy' => 'photo.deleted',
        'landlord.invitations.resend' => 'invitation.resent',
        'landlord.invitations.destroy' => 'invitation.revoked',
        'landlord.members.destroy' => 'member.removed',
    ];
    expect(array_keys($expected))->toEqualCanonicalizing(array_keys($routes));

    foreach ($routes as $name => [$method, $path, $body]) {
        $count = auditRows()->count();

        $response = $this->actingAs($owner)->call($method, $path, $body);
        expect($response->isRedirect())->toBeTrue("{$name} should redirect for the owner, got {$response->getStatusCode()}: ".($response->exception?->getMessage() ?? ''));

        $rows = auditRows()->slice($count);
        expect($rows)->toHaveCount(1, "{$name} should write one audit row")
            ->and($rows->first()->action)->toBe($expected[$name], "{$name} audit action");
    }
});

it('T7: a renter gets 403 on every write route too', function (): void {
    $account = t7Account();
    $renter = User::factory()->renter()->create();

    foreach (t7WriteRoutes($account) as $name => [$method, $path, $body]) {
        $this->actingAs($renter)->call($method, $path, $body)->assertForbidden("{$name} should be 403 for a renter");
    }
});
