<?php

declare(strict_types=1);

use App\Actions\PublishProperty;
use App\Enums\LandlordRole;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\CannotPublish;
use App\Models\LandlordAccount;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

// Slice 4.3: POST and DELETE /landlord/properties/{property}/publish (owner only), and the checklist on the
// property page. Publishing needs ALL of: a verified account, not archived, a room that isn't archived with a
// space that isn't, a ready photo, and payment instructions. The action checks again on locked rows.

beforeEach(function (): void {
    fakeMediaDisks();
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/**
 * An owner of a verified account with a draft that has everything a listing needs.
 *
 * @return array{0: User, 1: Property, 2: Room}
 */
function publishableDraft(): array
{
    $owner = landlordUser();
    $property = Property::factory()->create(['landlord_account_id' => accountIdOf($owner), 'name' => 'Alpha Home']);
    $room = Room::factory()->shared(beds: 2)->for($property)->create();
    PropertyPhoto::factory()->for($property)->create();

    return [$owner, $property, $room];
}

/** The checklist as the page gets it: key => done. */
function checklistOf(mixed $test, User $as, Property $property): array
{
    $items = $test->actingAs($as)->get("/landlord/properties/{$property->id}")->assertOk()->viewData('page')['props']['checklist'];

    return collect($items)->mapWithKeys(fn (array $item): array => [$item['key'] => $item['done']])->all();
}

it('publishes a complete draft: published, first_published_at set, one audit row, and the room page opens', function (): void {
    [$owner, $property, $room] = publishableDraft();
    auth()->logout();
    $this->get("/rooms/{$room->id}")->assertNotFound();

    expect(checklistOf($this, $owner, $property))->toBe(['verified' => true, 'room' => true, 'photo' => true, 'payment' => true, 'address' => true]);

    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/publish")
        ->assertRedirect("/landlord/properties/{$property->id}")
        ->assertSessionHas('status', 'Alpha Home is published. Renters can now find and reserve it.');

    $property->refresh();
    expect($property->is_published)->toBeTrue()
        ->and($property->first_published_at?->equalTo(now()))->toBeTrue();

    $row = auditRows('property.published')->sole();
    expect($row->actor_id)->toBe($owner->id)
        ->and(json_decode($row->after, true))->toHaveKey('is_published', true)->toHaveKey('first_published_at');

    // Published: no checklist any more, and the listing is reachable.
    $this->actingAs($owner)->get("/landlord/properties/{$property->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('checklist', null)->where('property.is_published', true)->where('property.can_publish', true));
    auth()->logout();
    $this->get("/listings/{$property->city_slug}/{$property->slug}")->assertOk();
    $this->get("/rooms/{$room->id}")->assertStatus(301);

    // Again: nothing happens.
    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/publish")->assertRedirect();
    expect(auditRows('property.published'))->toHaveCount(1);
});

it('refuses to publish while anything is missing, names it, and the page\'s checklist shows the same', function (string $key, Closure $break, string $named): void {
    [$owner, $property, $room] = publishableDraft();
    $break($property, $room);

    expect(checklistOf($this, $owner, $property)[$key])->toBeFalse();

    $response = $this->actingAs($owner)->postJson("/landlord/properties/{$property->id}/publish")->assertStatus(422);
    expect($response->json('message'))->toStartWith('Alpha Home can\'t be published yet. Still needed: ')->toContain($named);

    expect($property->fresh()->is_published)->toBeFalse()
        ->and($property->fresh()->first_published_at)->toBeNull()
        ->and(auditRows('property.published'))->toBeEmpty();
})->with([
    'the account is not verified' => ['verified', fn (Property $p) => LandlordAccount::query()->whereKey($p->landlord_account_id)->update(['is_verified' => false, 'verified_at' => null]), 'We have checked your account'],
    'no room' => ['room', fn (Property $p, Room $r) => $r->spaces()->delete() && $r->delete(), 'A room with at least one bed or space to rent'],
    'the only room is archived' => ['room', fn (Property $p, Room $r) => $r->forceFill(['archived_at' => now()])->save(), 'A room with'],
    'every space of the room is archived' => ['room', fn (Property $p, Room $r) => $r->spaces()->update(['archived_at' => now()]), 'A room with'],
    'no photo' => ['photo', fn (Property $p) => $p->photos()->delete(), 'At least one photo'],
    'the only photo is still resizing' => ['photo', fn (Property $p) => $p->photos()->update(['status' => 'processing']), 'At least one photo'],
    'the only photo failed' => ['photo', fn (Property $p) => $p->photos()->update(['status' => 'failed']), 'At least one photo'],
    'no payment instructions' => ['payment', fn (Property $p) => $p->forceFill(['payment_instructions' => null])->save(), 'How renters pay the reservation fee'],
    'blank payment instructions' => ['payment', fn (Property $p) => $p->forceFill(['payment_instructions' => '  '])->save(), 'How renters pay'],
    // Slice 4.4: a property from before the places list, with only the old text.
    'no linked barangay (from before 4.4)' => ['address', fn (Property $p) => $p->forceFill(['barangay_id' => null])->save(), 'A complete address'],
    'no linked city or barangay (from before 4.4)' => ['address', fn (Property $p) => $p->forceFill(['city_id' => null, 'barangay_id' => null])->save(), 'A complete address'],
    'the property is archived' => ['archived', fn (Property $p) => $p->forceFill(['archived_at' => now()])->save(), 'The property is not archived'],
]);

it('lists everything that is missing at once, with a link to fix each thing the owner can fix', function (): void {
    $owner = landlordUser(verifiedAccount: false);
    $property = Property::factory()->unlinked()->create(['landlord_account_id' => accountIdOf($owner), 'name' => 'Bare Lot', 'payment_instructions' => null]);

    $this->actingAs($owner)->get("/landlord/properties/{$property->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('checklist', 5)
        ->where('checklist.0.key', 'verified')->where('checklist.0.done', false)->where('checklist.0.fix', null)
        ->where('checklist.0.hint', fn (string $hint): bool => str_contains($hint, 'still checking your account'))
        ->where('checklist.1.fix.href', "/landlord/properties/{$property->id}/rooms/create")
        ->where('checklist.2.fix.href', '#photos')
        ->where('checklist.3.fix.href', "/landlord/properties/{$property->id}/edit")
        ->where('checklist.4.key', 'address')->where('checklist.4.done', false)->where('checklist.4.fix.href', "/landlord/properties/{$property->id}/edit"));

    $message = $this->actingAs($owner)->postJson("/landlord/properties/{$property->id}/publish")->assertStatus(422)->json('message');
    expect($message)->toContain('We have checked your account')->toContain('A room with')->toContain('At least one photo')->toContain('How renters pay')->toContain('A complete address');
});

it('keeps the checklist from a caretaker, who can\'t publish or unpublish', function (): void {
    [$owner, $property] = publishableDraft();
    $caretaker = landlordUserOf(accountIdOf($owner), LandlordRole::Caretaker);

    $this->actingAs($caretaker)->get("/landlord/properties/{$property->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('checklist', null)->where('property.can_publish', false));
    $this->actingAs($caretaker)->post("/landlord/properties/{$property->id}/publish")->assertForbidden();
    $this->actingAs($caretaker)->delete("/landlord/properties/{$property->id}/publish")->assertForbidden();

    expect($property->fresh()->is_published)->toBeFalse();
});

it('unpublishes at any time, and existing reservations are not touched', function (): void {
    [$owner, $property, $room] = ownerWithRoom(beds: 3);
    $spaces = $room->spaces()->orderBy('id')->get();
    $held = Reservation::factory()->for($spaces[0], 'space')->for(User::factory()->renter()->create(), 'renter')->create();
    $waiting = Reservation::factory()->for($spaces[1], 'space')->for(User::factory()->renter()->create(), 'renter')->proofUploaded()->create();
    $confirmed = Reservation::factory()->for($spaces[2], 'space')->for(User::factory()->renter()->create(), 'renter')->confirmed()->create();
    $before = DB::table('reservations')->orderBy('id')->get()->toJson();
    $proofs = DB::table('payment_proofs')->orderBy('id')->get()->toJson();
    $firstPublished = $property->first_published_at;

    $this->actingAs($owner)->delete("/landlord/properties/{$property->id}/publish")
        ->assertRedirect("/landlord/properties/{$property->id}")
        ->assertSessionHas('status', 'Alpha Home is no longer published. Reservations it already has are not affected.');

    $property->refresh();
    expect($property->is_published)->toBeFalse()
        ->and($property->first_published_at?->equalTo($firstPublished))->toBeTrue() // the listing link stays the property's
        ->and(DB::table('reservations')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(DB::table('payment_proofs')->orderBy('id')->get()->toJson())->toBe($proofs)
        ->and($held->fresh()->status)->toBe(ReservationStatus::Held)
        ->and($waiting->fresh()->status)->toBe(ReservationStatus::ProofUploaded)
        ->and($confirmed->fresh()->status)->toBe(ReservationStatus::Confirmed);

    $row = auditRows('property.unpublished')->sole();
    expect($row->actor_id)->toBe($owner->id)
        ->and(json_decode($row->before, true))->toEqual(['is_published' => true])
        ->and(json_decode($row->after, true))->toEqual(['is_published' => false]);

    // The landlord still sees the reservations; the renter still sees theirs; nobody new can reserve.
    $this->actingAs($owner)->get("/landlord/reservations/{$confirmed->id}")->assertOk();
    $this->actingAs($held->renter)->get("/reservations/{$held->id}")->assertOk();
    $this->actingAs(User::factory()->renter()->create())->get("/rooms/{$room->id}")->assertNotFound();

    // Again: nothing happens. And it can be published again, keeping the first date.
    $this->actingAs($owner)->delete("/landlord/properties/{$property->id}/publish")->assertRedirect();
    expect(auditRows('property.unpublished'))->toHaveCount(1);

    PropertyPhoto::factory()->for($property)->create();
    $this->travel(3)->days();
    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/publish")->assertRedirect();
    expect($property->fresh()->is_published)->toBeTrue()
        ->and($property->fresh()->first_published_at?->equalTo($firstPublished))->toBeTrue();
});

it('locks the account, then every room by id, then the property row, and checks with locking reads only', function (): void {
    [, $property] = publishableDraft();
    Room::factory()->for($property)->create();

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });
    app(PublishProperty::class)->handle($property);

    // ArchiveProperty's order (rooms by id, then the property), so a publish and an archive can't interleave.
    expect($queries[0])->toMatch('/^select \* from `landlord_accounts` where `landlord_accounts`\.`id` = \? limit 1 lock in share mode$/')
        ->and($queries[1])->toMatch('/^select `id` from `rooms` where `property_id` = \? order by `id` asc for update$/')
        ->and($queries[2])->toMatch('/^select \* from `properties` where `properties`\.`id` = \? limit 1 for update$/');

    foreach ($queries as $sql) {
        if (str_starts_with($sql, 'select')) {
            expect($sql)->toMatch('/ (for update|lock in share mode)$/')->and($sql)->not->toMatch('/\(\s*select/');
        }
    }
});

it('refuses on what the locked rows say, not on the model it was handed', function (): void {
    [, $property] = publishableDraft();
    $stale = Property::query()->findOrFail($property->id);
    // Archived by another request after this one loaded the property.
    DB::table('properties')->where('id', $property->id)->update(['archived_at' => now()]);

    expect(fn () => app(PublishProperty::class)->handle($stale))->toThrow(CannotPublish::class, 'The property is not archived');
    expect($property->fresh()->is_published)->toBeFalse();
});
