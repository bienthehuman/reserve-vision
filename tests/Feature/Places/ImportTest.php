<?php

declare(strict_types=1);

use App\Models\Barangay;
use App\Models\City;
use App\Models\Property;
use App\Models\Province;
use App\Support\Places\PlacesImporter;
use Illuminate\Support\Facades\DB;

// Slice 4.4: `php artisan places:import` against the fixture in tests/Fixtures/psgc,
// and once against the real files.

function fixtureDir(): string
{
    return base_path('tests/Fixtures/psgc');
}

/**
 * A copy of the fixture with some lines changed, as a later PSGC publication would.
 *
 * @param  array<string, callable(list<string>): list<string>>  $edits  file => lines => lines
 */
function editedFixture(array $edits): string
{
    $directory = sys_get_temp_dir().'/rv-psgc-'.bin2hex(random_bytes(4));
    mkdir($directory);
    foreach (['provinces.csv', 'cities.csv', 'barangays.csv'] as $file) {
        $lines = file(fixtureDir()."/{$file}", FILE_IGNORE_NEW_LINES) ?: [];
        file_put_contents("{$directory}/{$file}", implode("\n", ($edits[$file] ?? fn (array $l): array => $l)($lines))."\n");
    }

    return $directory;
}

function importPlaces(?string $directory = null): void
{
    test()->artisan('places:import', ['--path' => $directory ?? fixtureDir()])->assertSuccessful();
}

it('imports every row, with Metro Manila as a province and Manila\'s barangays under their districts', function (): void {
    $this->artisan('places:import', ['--path' => fixtureDir()])
        ->expectsOutputToContain('provinces: 4 in the files, 4 new')
        ->expectsOutputToContain('cities: 6 in the files, 6 new')
        ->expectsOutputToContain('barangays: 10 in the files, 10 new')
        ->assertSuccessful();

    $manila = City::query()->where('slug', 'manila')->sole();
    expect($manila->province->name)->toBe('Metro Manila')
        ->and($manila->province->slug)->toBe('metro-manila')
        ->and(Barangay::query()->where('city_id', $manila->id)->orderBy('psgc_code')->get()->map->label->all())
        ->toBe(['Barangay 1, Tondo', 'Barangay 395, Sampaloc', 'Barangay 396, Sampaloc'])
        ->and(Barangay::query()->where('name', 'Lahug')->sole()->district)->toBeNull();
});

it('is idempotent: a second run adds nothing and changes nothing', function (): void {
    importPlaces();
    $snapshot = fn (): string => md5((string) json_encode([
        DB::table('provinces')->orderBy('id')->get(), DB::table('cities')->orderBy('id')->get(), DB::table('barangays')->orderBy('id')->get(),
    ]));
    $before = $snapshot();

    $this->artisan('places:import', ['--path' => fixtureDir()])
        ->expectsOutputToContain('barangays: 10 in the files, 0 new')
        ->assertSuccessful();

    expect($snapshot())->toBe($before);
});

it('gives a repeated name its province in the slug, and a unique name none', function (): void {
    importPlaces();

    expect(City::query()->where('name', 'San Jose')->orderBy('slug')->pluck('slug')->all())->toBe(['san-jose-batangas', 'san-jose-tarlac'])
        ->and(City::query()->where('name', 'Lipa')->value('slug'))->toBe('lipa')
        ->and(City::query()->where('name', 'Quezon City')->value('slug'))->toBe('quezon-city');
});

it('pins the seven slugs that had listing URLs, whatever the files call those cities', function (): void {
    $renamed = editedFixture(['cities.csv' => fn (array $lines): array => str_replace(['1300000000,Manila,', '"Cebu City"'], ['1300000000,Maynila,', '"Sugbo"'], $lines)]);

    importPlaces($renamed);

    expect(City::query()->where('psgc_code', '1380600000')->sole())->name->toBe('Maynila')->slug->toBe('manila')
        ->and(City::query()->where('psgc_code', '0730600000')->sole())->name->toBe('Sugbo')->slug->toBe('cebu-city')
        ->and(PlacesImporter::PINNED)->toBe([
            '1380600000' => 'manila', '1381300000' => 'quezon-city', '1380300000' => 'makati', '0730600000' => 'cebu-city',
            '1130700000' => 'davao-city', '1430300000' => 'baguio', '0631000000' => 'iloilo-city',
        ]);
});

it('never changes a slug on a later import: a renamed city keeps its slug, and a new namesake gets the province', function (): void {
    importPlaces();
    $lipa = City::query()->where('slug', 'lipa')->sole();

    $next = editedFixture(['cities.csv' => fn (array $lines): array => [
        ...str_replace('0401020000,0401000000,Lipa,city', '0401020000,0401000000,"Lipa City",city', $lines),
        '0702299000,0702200000,Lipa,municipality',
    ]]);
    importPlaces($next);

    expect($lipa->fresh())->name->toBe('Lipa City')->slug->toBe('lipa')
        ->and(City::query()->where('psgc_code', '0702299000')->value('slug'))->toBe('lipa-cebu');
});

it('keeps a place that is no longer in the files, and reports it', function (): void {
    importPlaces();
    $gone = editedFixture(['barangays.csv' => fn (array $lines): array => array_values(array_filter($lines, fn (string $line): bool => ! str_contains($line, 'Marawoy')))]);

    $this->artisan('places:import', ['--path' => $gone])
        ->expectsOutputToContain('1 places are no longer in the files and were kept:')
        ->expectsOutputToContain('barangays: Marawoy (0401020003)')
        ->assertSuccessful();

    expect(Barangay::query()->where('name', 'Marawoy')->exists())->toBeTrue();
});

it('links properties from before the list by city slug and barangay name, only where exactly one barangay matches', function (): void {
    $linked = Property::factory()->unlinked('Barangay 395, Sampaloc', 'Manila', 'manila')->create();
    $byName = Property::factory()->unlinked('Loyola Heights', 'Quezon City', 'quezon-city', 'Metro Manila')->create();
    $districtOnly = Property::factory()->unlinked('Sampaloc', 'Manila', 'manila')->create();
    $ambiguous = Property::factory()->unlinked('San Isidro', 'Lipa', 'lipa', 'Batangas')->create();
    $nowhere = Property::factory()->unlinked('Somewhere', 'Atlantis', 'atlantis', 'Nowhere')->create();

    $this->artisan('places:import', ['--path' => fixtureDir()])
        ->expectsOutputToContain('Properties linked: 4 to a city, 2 to a barangay')
        ->expectsOutputToContain('Properties without a city: 1')
        ->expectsOutputToContain('Properties without a barangay: 3')
        ->assertSuccessful();

    $barangay = fn (string $name): int => Barangay::query()->where('name', $name)->sole()->id;
    expect($linked->fresh())->city_id->toBe(City::query()->where('slug', 'manila')->value('id'))->barangay_id->toBe($barangay('Barangay 395'))
        ->and($byName->fresh()->barangay_id)->toBe($barangay('Loyola Heights'))
        ->and($districtOnly->fresh())->city_id->not->toBeNull()->barangay_id->toBeNull()
        ->and($ambiguous->fresh())->city_id->not->toBeNull()->barangay_id->toBeNull()
        ->and($nowhere->fresh())->city_id->toBeNull()->barangay_id->toBeNull()
        // The text copies are left as they were: the owner sees them as a hint on the form.
        ->and($districtOnly->fresh()->barangay)->toBe('Sampaloc');
});

it('refuses broken files and writes nothing', function (string $file, Closure $edit, string $message): void {
    $broken = editedFixture([$file => $edit]);

    $this->artisan('places:import', ['--path' => $broken])->expectsOutputToContain($message)->assertFailed();

    expect(Province::query()->count())->toBe(0)->and(City::query()->count())->toBe(0);
})->with([
    'a wrong header' => ['cities.csv', fn (array $lines): array => ['code,province,name,kind', ...array_slice($lines, 1)], 'the first line must be psgc_code,province_code,name,kind'],
    'a code that is not 10 digits' => ['provinces.csv', fn (array $lines): array => [...$lines, '12345,Atlantis'], 'a PSGC code is 10 digits'],
    'an unknown province' => ['cities.csv', fn (array $lines): array => [...$lines, '9999999000,9999900000,Atlantis,city'], 'Atlantis (9999999000) has an unknown province or kind'],
    'an unknown city' => ['barangays.csv', fn (array $lines): array => [...$lines, '9999999001,9999999000,Atlantis,'], 'Atlantis (9999999001) has an unknown city'],
    'a code listed twice' => ['barangays.csv', fn (array $lines): array => [...$lines, $lines[1]], 'barangays.csv lists a PSGC code twice'],
]);

it('imports the real files: the counts, the seven slugs and how the edge cases were placed', function (): void {
    $this->artisan('places:import')->assertSuccessful();

    $provinceOf = fn (string $citySlug): string => City::query()->where('slug', $citySlug)->sole()->province->name;
    expect(Province::query()->count())->toBe(84)
        ->and(City::query()->count())->toBe(1642)
        ->and(Barangay::query()->count())->toBe(42010)
        ->and(City::query()->whereIn('slug', array_values(PlacesImporter::PINNED))->orderBy('slug')->get()->map(fn (City $c): string => "{$c->slug}: {$c->name}, {$c->province->name}")->all())
        ->toBe([
            'baguio: Baguio, Benguet', 'cebu-city: Cebu City, Cebu', 'davao-city: Davao City, Davao del Sur', 'iloilo-city: Iloilo City, Iloilo',
            'makati: Makati, Metro Manila', 'manila: Manila, Metro Manila', 'quezon-city: Quezon City, Metro Manila',
        ])
        // Cities outside every province, under the one they sit in.
        ->and($provinceOf('isabela-city'))->toBe('Basilan')
        ->and($provinceOf('cotabato-city'))->toBe('Maguindanao del Norte')
        ->and($provinceOf('cagayan-de-oro'))->toBe('Misamis Oriental')
        ->and($provinceOf('pateros'))->toBe('Metro Manila')
        ->and($provinceOf('kapalawan'))->toBe('Special Geographic Area')
        // Names repeated across the country: the province is in the slug.
        ->and(City::query()->where('slug', 'san-fernando-city-pampanga')->value('name'))->toBe('San Fernando City')
        ->and(City::query()->where('slug', 'samal-city')->sole()->province->name)->toBe('Davao del Norte')
        // Manila: every barangay has a district, and there are 14.
        ->and(Barangay::query()->where('city_id', City::query()->where('slug', 'manila')->value('id'))->whereNull('district')->count())->toBe(0)
        ->and(Barangay::query()->whereNotNull('district')->distinct()->count('district'))->toBe(14)
        ->and(Barangay::query()->where('psgc_code', '1380606001')->sole()->label)->toBe('Barangay 395, Sampaloc');
});
