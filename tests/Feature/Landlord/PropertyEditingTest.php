<?php

declare(strict_types=1);

use App\Enums\GenderPolicy;
use App\Enums\LandlordRole;
use App\Models\Amenity;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// Slice 4.1: creating and editing a property (owner only; ACCESS.md matrix row
// "Create or edit properties, rooms, spaces, rates").

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

it('creates a draft property for the owner\'s account, with one audit row', function (): void {
    $owner = landlordUser();
    $wifi = Amenity::factory()->create(['slug' => 'wifi-t', 'name' => 'Wi-Fi T']);

    $response = $this->actingAs($owner)->post('/landlord/properties', propertyForm(['amenities' => [$wifi->id], 'lat' => '14.65', 'lng' => '121.05']));

    $property = Property::query()->sole();
    $response->assertRedirect("/landlord/properties/{$property->id}");
    expect($property->landlord_account_id)->toBe(accountIdOf($owner))
        ->and($property->name)->toBe('Santos Bedspace House')
        ->and($property->slug)->toBe('santos-bedspace-house')
        ->and($property->city)->toBe('Quezon City')->and($property->city_slug)->toBe('quezon-city')
        ->and($property->lat)->toBe('14.650000')->and($property->lng)->toBe('121.050000')
        ->and($property->is_published)->toBeFalse()->and($property->hasBeenPublished())->toBeFalse()
        ->and($property->payment_instructions)->toBe('GCash 0917 123 4567 (J. Santos).')
        ->and($property->amenities()->pluck('slug')->all())->toBe(['wifi-t']);

    $rows = auditRows();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->action)->toBe('property.created')
        ->and($rows->first()->subject_id)->toBe($property->id)
        ->and($rows->first()->actor_id)->toBe($owner->id)
        ->and(json_decode($rows->first()->after, true))->toMatchArray(['name' => 'Santos Bedspace House', 'amenities' => ['wifi-t']]);
});

it('starts the coordinates at the city\'s centre when they are left empty', function (): void {
    $owner = landlordUser();

    $this->actingAs($owner)->post('/landlord/properties', propertyForm(['city' => 'cebu-city']))->assertRedirect();

    expect(Property::query()->sole())->lat->toBe('10.315700')->lng->toBe('123.885400');
});

it('generates a unique slug per city: -2, -3 on a clash, and the same name in another city is fine', function (): void {
    $owner = landlordUser();
    $other = landlordUser();

    $this->actingAs($owner)->post('/landlord/properties', propertyForm());
    $this->actingAs($owner)->post('/landlord/properties', propertyForm());
    $this->actingAs($other)->post('/landlord/properties', propertyForm()); // another account, same city: still unique
    $this->actingAs($owner)->post('/landlord/properties', propertyForm(['city' => 'makati']));

    expect(Property::query()->withoutGlobalScopes()->orderBy('id')->get()->map(fn (Property $p) => "{$p->city_slug}/{$p->slug}")->all())
        ->toBe(['quezon-city/santos-bedspace-house', 'quezon-city/santos-bedspace-house-2', 'quezon-city/santos-bedspace-house-3', 'makati/santos-bedspace-house']);
});

it('refuses an explicit slug that another property uses in the city', function (): void {
    $owner = landlordUser();
    $this->actingAs($owner)->post('/landlord/properties', propertyForm(['slug' => 'my-house']))->assertRedirect();

    $this->actingAs($owner)->post('/landlord/properties', propertyForm(['slug' => 'my-house']))->assertSessionHasErrors('slug');
    $this->actingAs($owner)->post('/landlord/properties', propertyForm(['slug' => 'my-house', 'city' => 'makati']))->assertSessionHasNoErrors();

    expect(Property::query()->count())->toBe(2);
});

it('validates the form', function (array $overrides, string $field): void {
    $owner = landlordUser();

    $this->actingAs($owner)->post('/landlord/properties', propertyForm($overrides))->assertSessionHasErrors($field);

    expect(Property::query()->count())->toBe(0)->and(auditRows())->toBeEmpty();
})->with([
    'no name' => [['name' => ''], 'name'],
    'bad kind' => [['kind' => 'castle'], 'kind'],
    'a city that is not in the list' => [['city' => 'atlantis'], 'city'],
    'no address' => [['address' => ''], 'address'],
    'bad gender policy' => [['gender_policy' => 'pets_only'], 'gender_policy'],
    'latitude out of range' => [['lat' => '91', 'lng' => '121'], 'lat'],
    'longitude not a number' => [['lat' => '14.5', 'lng' => 'east'], 'lng'],
    'latitude without longitude' => [['lat' => '14.5', 'lng' => null], 'lng'],
    'too many decimals' => [['lat' => '14.1234567', 'lng' => '121'], 'lat'],
    'a slug with capitals' => [['slug' => 'My House'], 'slug'],
    'an unknown amenity' => [['amenities' => [999999]], 'amenities.0'],
    'overlong payment instructions' => [['payment_instructions' => str_repeat('x', 1001)], 'payment_instructions'],
]);

it('allows empty house rules, barangay and payment instructions on a draft', function (): void {
    $owner = landlordUser();

    $this->actingAs($owner)->post('/landlord/properties', propertyForm(['house_rules' => '', 'barangay' => '', 'payment_instructions' => '']))->assertSessionHasNoErrors();

    expect(Property::query()->sole())->house_rules->toBeNull()->barangay->toBeNull()->payment_instructions->toBeNull();
});

it('updates a property, with one audit row of the changed fields only', function (): void {
    [$owner, $property] = ownerWithRoom();
    $property->forceFill(['first_published_at' => null, 'is_published' => false])->save();

    $this->actingAs($owner)->put("/landlord/properties/{$property->id}", propertyForm([
        'kind' => $property->kind->value, 'name' => 'Alpha Home Annex', 'city' => 'manila', 'slug' => 'alpha-annex', 'address' => '99 Rizal Street', 'gender_policy' => 'female_only',
        'payment_instructions' => 'Maya 0918 111 2222.',
    ]))->assertRedirect("/landlord/properties/{$property->id}");

    $property->refresh();
    expect($property->name)->toBe('Alpha Home Annex')->and($property->slug)->toBe('alpha-annex')
        ->and($property->gender_policy)->toBe(GenderPolicy::FemaleOnly)->and($property->payment_instructions)->toBe('Maya 0918 111 2222.');

    $row = auditRows('property.updated')->sole();
    $before = json_decode($row->before, true);
    $after = json_decode($row->after, true);
    expect($before)->toHaveKeys(['name', 'slug', 'address', 'gender_policy', 'payment_instructions'])
        ->and($before)->not->toHaveKey('kind')->not->toHaveKey('city_slug')
        ->and($after)->toMatchArray(['name' => 'Alpha Home Annex', 'slug' => 'alpha-annex', 'gender_policy' => 'female_only'])
        ->and(auditRows())->toHaveCount(1);
});

it('writes no audit row when nothing changed', function (): void {
    [$owner, $property] = ownerWithRoom();
    $form = propertyForm([
        'kind' => $property->kind->value, 'name' => $property->name, 'city' => $property->city_slug, 'barangay' => $property->barangay, 'address' => $property->address,
        'lat' => $property->lat, 'lng' => $property->lng, 'gender_policy' => 'any', 'house_rules' => $property->house_rules,
        'payment_instructions' => $property->payment_instructions,
    ]);

    $this->actingAs($owner)->put("/landlord/properties/{$property->id}", $form)->assertRedirect();

    expect(auditRows())->toBeEmpty();
});

it('syncs the amenities and records them in the audit row', function (): void {
    [$owner, $property] = ownerWithRoom();
    [$a, $b] = [Amenity::factory()->create(['slug' => 'a-one']), Amenity::factory()->create(['slug' => 'b-two'])];
    $property->amenities()->sync([$a->id]);
    $form = fn (array $ids) => propertyForm(['name' => $property->name, 'city' => 'manila', 'amenities' => $ids, 'lat' => $property->lat, 'lng' => $property->lng]);

    $this->actingAs($owner)->put("/landlord/properties/{$property->id}", $form([$b->id]))->assertRedirect();

    expect($property->amenities()->pluck('slug')->all())->toBe(['b-two']);
    $row = auditRows('property.updated')->last();
    expect(json_decode($row->before, true)['amenities'])->toBe(['a-one'])->and(json_decode($row->after, true)['amenities'])->toBe(['b-two']);
});

describe('the web address of a property that has been published', function (): void {
    it('can\'t change its slug or its city, and the refusal changes nothing', function (): void {
        [$owner, $property] = ownerWithRoom(); // published, so first_published_at is set
        $same = ['name' => 'Renamed', 'city' => 'manila', 'lat' => $property->lat, 'lng' => $property->lng];

        $this->actingAs($owner)->put("/landlord/properties/{$property->id}", propertyForm([...$same, 'slug' => 'something-else']))->assertStatus(422);
        $this->actingAs($owner)->put("/landlord/properties/{$property->id}", propertyForm([...$same, 'city' => 'makati']))->assertStatus(422);

        expect($property->fresh())->slug->toBe('alpha-home')->city_slug->toBe('manila')->name->toBe('Alpha Home')
            ->and(auditRows())->toBeEmpty();
    });

    it('keeps its slug when it is left empty or sent unchanged, and the name can still change', function (): void {
        [$owner, $property] = ownerWithRoom();
        $form = propertyForm(['name' => 'Renamed', 'city' => 'manila', 'lat' => $property->lat, 'lng' => $property->lng]);

        $this->actingAs($owner)->put("/landlord/properties/{$property->id}", [...$form, 'slug' => null])->assertRedirect();
        $this->actingAs($owner)->put("/landlord/properties/{$property->id}", [...$form, 'slug' => 'alpha-home', 'name' => 'Renamed Again'])->assertRedirect();

        expect($property->fresh())->slug->toBe('alpha-home')->name->toBe('Renamed Again');
    });

    it('is shown as locked on the form, and the slug is editable on a draft', function (): void {
        [$owner, $property] = ownerWithRoom();

        $this->actingAs($owner)->get("/landlord/properties/{$property->id}/edit")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Landlord/Properties/Form')->where('locked', true)->where('property.slug', 'alpha-home'));

        $property->forceFill(['first_published_at' => null, 'is_published' => false])->save();
        $this->actingAs($owner)->get("/landlord/properties/{$property->id}/edit")->assertInertia(fn (AssertableInertia $page) => $page->where('locked', false));
    });
});

it('refuses a slug a draft property tries to take from another property in its city', function (): void {
    [$owner, $property] = ownerWithRoom();
    Property::factory()->create(['city' => 'Makati', 'city_slug' => 'makati', 'slug' => 'taken']);
    $property->forceFill(['first_published_at' => null, 'is_published' => false])->save();

    $this->actingAs($owner)->put("/landlord/properties/{$property->id}", propertyForm(['city' => 'makati', 'slug' => 'taken', 'lat' => $property->lat, 'lng' => $property->lng]))
        ->assertSessionHasErrors('slug');

    expect($property->fresh()->city_slug)->toBe('manila');
});

it('serves the empty and the filled form with the cities and amenities', function (): void {
    [$owner, $property] = ownerWithRoom();
    Amenity::factory()->create(['name' => 'Zebra Lounge']);

    $this->actingAs($owner)->get('/landlord/properties/create')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Landlord/Properties/Form')->where('property', null)->where('locked', false)
        ->has('places', 7)->where('places.0', ['slug' => 'manila', 'city' => 'Manila', 'lat' => '14.604200', 'lng' => '120.982200'])
        ->where('amenities', fn ($amenities) => collect($amenities)->pluck('name')->contains('Zebra Lounge')));

    $this->actingAs($owner)->get("/landlord/properties/{$property->id}/edit")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('property.name', 'Alpha Home')->where('property.city', 'manila'));
});

it('answers another account\'s property with 404 and a caretaker with 403', function (): void {
    [$owner, $property] = ownerWithRoom();
    $theirs = Property::factory()->published()->create(['landlord_account_id' => accountIdOf(landlordUser()), 'name' => 'Not Yours']);
    $caretaker = landlordUserOf(accountIdOf($owner), LandlordRole::Caretaker);

    $this->actingAs($owner)->put("/landlord/properties/{$theirs->id}", propertyForm())->assertNotFound();
    $this->actingAs($owner)->get("/landlord/properties/{$theirs->id}/edit")->assertNotFound();
    $this->actingAs($caretaker)->put("/landlord/properties/{$property->id}", propertyForm())->assertForbidden();
    $this->actingAs($caretaker)->post('/landlord/properties', propertyForm())->assertForbidden();

    expect($theirs->fresh()->name)->toBe('Not Yours')->and(auditRows())->toBeEmpty();
});
