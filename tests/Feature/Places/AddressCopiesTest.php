<?php

declare(strict_types=1);

use App\Models\Property;
use Illuminate\Support\Facades\DB;

// Slice 4.4: a property's text columns (barangay, city, city_slug, province) are copies of
// its linked city and barangay, written only by the actions. Lists and the listing URL read them.

/** The properties whose copies differ from the rows they link to. */
function propertiesWithStaleCopies(): array
{
    return DB::table('properties')
        ->join('cities', 'cities.id', '=', 'properties.city_id')
        ->join('provinces', 'provinces.id', '=', 'cities.province_id')
        ->join('barangays', 'barangays.id', '=', 'properties.barangay_id')
        ->where(fn ($query) => $query
            ->whereColumn('barangays.city_id', '!=', 'cities.id')
            ->orWhereColumn('properties.city', '!=', 'cities.name')
            ->orWhereColumn('properties.city_slug', '!=', 'cities.slug')
            ->orWhereColumn('properties.province', '!=', 'provinces.name')
            ->orWhereRaw("properties.barangay != IF(barangays.district IS NULL, barangays.name, CONCAT(barangays.name, ', ', barangays.district))"))
        ->pluck('properties.id')
        ->all();
}

it('writes copies equal to the linked rows when a property is created and updated', function (): void {
    $owner = landlordUser();

    $this->actingAs($owner)->post('/landlord/properties', propertyForm(placeFields(placeIn('manila'))))->assertRedirect();
    $property = Property::query()->sole();
    expect($property)->city->toBe('Manila')->city_slug->toBe('manila')->province->toBe('Metro Manila')->barangay->toBe('Barangay 395, Sampaloc')
        ->and(propertiesWithStaleCopies())->toBe([]);

    $this->actingAs($owner)->put("/landlord/properties/{$property->id}", propertyForm(placeFields(placeIn('cebu-city'))))->assertRedirect();
    expect($property->fresh())->city->toBe('Cebu City')->city_slug->toBe('cebu-city')->province->toBe('Cebu')->barangay->toBe('Lahug')
        ->and(propertiesWithStaleCopies())->toBe([]);
});

it('records a change of place in the audit row, copies and links both', function (): void {
    [$owner, $property] = ownerWithRoom();
    $property->forceFill(['first_published_at' => null, 'is_published' => false])->save();
    $lahug = placeIn('cebu-city');

    $this->actingAs($owner)->put("/landlord/properties/{$property->id}", propertyForm(['name' => 'Alpha Home', ...placeFields($lahug)]))->assertRedirect();

    $after = json_decode(auditRows('property.updated')->sole()->after, true);
    expect($after)->toMatchArray(['city' => 'Cebu City', 'city_slug' => 'cebu-city', 'province' => 'Cebu', 'barangay' => 'Lahug', 'city_id' => $lahug->city_id, 'barangay_id' => $lahug->id]);
});
