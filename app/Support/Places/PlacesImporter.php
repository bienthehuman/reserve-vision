<?php

declare(strict_types=1);

namespace App\Support\Places;

use App\Data\PlacesImportReport;
use App\Enums\CityKind;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Loads `provinces.csv`, `cities.csv` and `barangays.csv` (made by `bin/psgc-convert`)
 * into the places tables, then links properties that have no city or barangay yet
 * (slice 4.4, `php artisan places:import`).
 *
 * - Every file is read and checked before anything is written; then one transaction.
 * - Upserts by `psgc_code` in chunks, as the `app` user (inserts and updates only).
 * - A slug is given once, to a new row, and never changed: the city's is in the public
 *   listing URL. `Str::slug(name)`, plus the province where the name repeats in the
 *   files or the bare slug is already a namesake's ("san-jose-batangas"), plus "-2"… if
 *   even that is taken. `PINNED` wins.
 * - A row that has gone from the files stays (a property may point at it) and is reported.
 * - A property is linked to the city whose slug is its `city_slug`, and to the barangay of
 *   that city whose name (or "name, district") is its `barangay`, when exactly one matches.
 */
final class PlacesImporter
{
    private const int CHUNK = 1000;

    /** The cities that had listing URLs before this list existed: their slugs never change. */
    public const array PINNED = [
        '1380600000' => 'manila',
        '1381300000' => 'quezon-city',
        '1380300000' => 'makati',
        '0730600000' => 'cebu-city',
        '1130700000' => 'davao-city',
        '1430300000' => 'baguio',
        '0631000000' => 'iloilo-city',
    ];

    private const array HEADERS = [
        'provinces' => ['psgc_code', 'name'],
        'cities' => ['psgc_code', 'province_code', 'name', 'kind'],
        'barangays' => ['psgc_code', 'city_code', 'name', 'district'],
    ];

    public function import(string $directory): PlacesImportReport
    {
        $started = microtime(true);
        $provinces = $this->read($directory, 'provinces');
        $cities = $this->read($directory, 'cities');
        $barangays = $this->read($directory, 'barangays');
        $this->check($provinces, $cities, $barangays);

        return DB::transaction(function () use ($provinces, $cities, $barangays, $started): PlacesImportReport {
            $before = ['provinces' => $this->count('provinces'), 'cities' => $this->count('cities'), 'barangays' => $this->count('barangays')];

            $provinceIds = $this->importProvinces($provinces);
            $cityIds = $this->importCities($cities, $provinceIds, $provinces);
            $this->importBarangays($barangays, $cityIds);

            $added = [];
            foreach ($before as $table => $count) {
                $added[$table] = $this->count($table) - $count;
            }
            $links = $this->linkProperties();

            return new PlacesImportReport(
                rows: ['provinces' => count($provinces), 'cities' => count($cities), 'barangays' => count($barangays)],
                added: $added,
                gone: [
                    ...$this->gone('provinces', $provinces),
                    ...$this->gone('cities', $cities),
                    ...$this->gone('barangays', $barangays),
                ],
                linkedCities: $links['linkedCities'],
                linkedBarangays: $links['linkedBarangays'],
                unlinkedCities: $links['unlinkedCities'],
                unlinkedBarangays: $links['unlinkedBarangays'],
                seconds: microtime(true) - $started,
            );
        });
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array<string, int> psgc code => id
     */
    private function importProvinces(array $rows): array
    {
        $taken = $this->slugs('provinces');
        $values = [];
        foreach ($rows as $row) {
            $slug = $taken[$row['psgc_code']] ?? $this->free(Str::slug($row['name']), $taken);
            $taken[$row['psgc_code']] = $slug;
            $values[] = ['psgc_code' => $row['psgc_code'], 'name' => $row['name'], 'slug' => $slug];
        }

        DB::table('provinces')->upsert($values, ['psgc_code'], ['name']);

        return $this->ids('provinces');
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, int>  $provinceIds
     * @param  list<array<string, string>>  $provinces
     * @return array<string, int> psgc code => id
     */
    private function importCities(array $rows, array $provinceIds, array $provinces): array
    {
        $provinceNames = array_column($provinces, 'name', 'psgc_code');
        $repeats = array_count_values(array_map(fn (array $row): string => mb_strtolower($row['name']), $rows));
        $taken = $this->slugs('cities');
        // A pinned slug is reserved even before its city is imported.
        foreach (self::PINNED as $code => $slug) {
            $taken[$code] ??= $slug;
        }

        $values = [];
        foreach ($rows as $row) {
            $code = $row['psgc_code'];
            if (! isset($taken[$code])) {
                // The bare name if it is unique in the files and free; else with the province (also when an
                // earlier import gave the bare slug to a namesake); "-2"… only if even that is taken.
                $bare = Str::slug($row['name']);
                $withProvince = Str::slug($row['name'].' '.$provinceNames[$row['province_code']]);
                $taken[$code] = $repeats[mb_strtolower($row['name'])] === 1 && ! in_array($bare, $taken, true)
                    ? $bare
                    : $this->free($withProvince, $taken);
            }

            $values[] = [
                'psgc_code' => $code,
                'province_id' => $provinceIds[$row['province_code']],
                'name' => $row['name'],
                'slug' => $taken[$code],
                'kind' => $row['kind'],
            ];
        }

        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            DB::table('cities')->upsert($chunk, ['psgc_code'], ['province_id', 'name', 'kind']);
        }

        return $this->ids('cities');
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, int>  $cityIds
     */
    private function importBarangays(array $rows, array $cityIds): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('barangays')->upsert(array_map(fn (array $row): array => [
                'psgc_code' => $row['psgc_code'],
                'city_id' => $cityIds[$row['city_code']],
                'name' => $row['name'],
                'district' => $row['district'] === '' ? null : $row['district'],
            ], $chunk), ['psgc_code'], ['city_id', 'name', 'district']);
        }
    }

    /**
     * Fills `city_id` and `barangay_id` where they are missing; the text copies stay as they are.
     *
     * @return array{linkedCities: int, linkedBarangays: int, unlinkedCities: int, unlinkedBarangays: int}
     */
    private function linkProperties(): array
    {
        $linkedCities = DB::table('properties')->whereNull('city_id')
            ->whereExists(fn (Builder $query) => $query->from('cities')->whereColumn('cities.slug', 'properties.city_slug'))
            ->update(['city_id' => DB::raw('(SELECT `cities`.`id` FROM `cities` WHERE `cities`.`slug` = `properties`.`city_slug`)')]);

        // Only an unambiguous match: a city can have two barangays of the same name.
        $match = 'FROM `barangays` WHERE `barangays`.`city_id` = `properties`.`city_id`'
            .' AND (`barangays`.`name` = `properties`.`barangay` OR CONCAT(`barangays`.`name`, \', \', `barangays`.`district`) = `properties`.`barangay`)';
        $linkedBarangays = DB::table('properties')->whereNull('barangay_id')->whereNotNull('city_id')
            ->whereRaw("(SELECT COUNT(*) {$match}) = 1")
            ->update(['barangay_id' => DB::raw("(SELECT MIN(`barangays`.`id`) {$match})")]);

        return [
            'linkedCities' => $linkedCities,
            'linkedBarangays' => $linkedBarangays,
            'unlinkedCities' => DB::table('properties')->whereNull('city_id')->count(),
            'unlinkedBarangays' => DB::table('properties')->whereNull('barangay_id')->count(),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function read(string $directory, string $table): array
    {
        $path = rtrim($directory, '/')."/{$table}.csv";
        $file = is_readable($path) ? fopen($path, 'r') : false;
        if ($file === false) {
            throw new RuntimeException("Can't read {$path}.");
        }

        $header = fgetcsv($file, escape: '');
        if ($header !== self::HEADERS[$table]) {
            throw new RuntimeException("{$path}: the first line must be ".implode(',', self::HEADERS[$table]).'.');
        }

        $rows = [];
        $line = 1;
        while (($fields = fgetcsv($file, escape: '')) !== false) {
            $line++;
            if ($fields === [null]) {
                continue; // a blank line
            }
            if (count($fields) !== count($header)) {
                throw new RuntimeException("{$path}, line {$line}: expected ".count($header).' fields.');
            }
            $row = array_combine($header, array_map(fn (?string $field): string => trim((string) $field), $fields));
            if (preg_match('/^\d{10}$/', $row['psgc_code']) !== 1 || $row['name'] === '') {
                throw new RuntimeException("{$path}, line {$line}: a PSGC code is 10 digits, and a name is required.");
            }
            $rows[] = $row;
        }
        fclose($file);

        return $rows;
    }

    /**
     * @param  list<array<string, string>>  $provinces
     * @param  list<array<string, string>>  $cities
     * @param  list<array<string, string>>  $barangays
     */
    private function check(array $provinces, array $cities, array $barangays): void
    {
        $provinceCodes = array_flip(array_column($provinces, 'psgc_code'));
        $cityCodes = array_flip(array_column($cities, 'psgc_code'));
        $kinds = array_map(fn (CityKind $kind): string => $kind->value, CityKind::cases());

        foreach (['provinces' => $provinces, 'cities' => $cities, 'barangays' => $barangays] as $table => $rows) {
            $codes = array_column($rows, 'psgc_code');
            if (count($codes) !== count(array_unique($codes))) {
                throw new RuntimeException("{$table}.csv lists a PSGC code twice.");
            }
        }
        foreach ($cities as $city) {
            if (! isset($provinceCodes[$city['province_code']]) || ! in_array($city['kind'], $kinds, true)) {
                throw new RuntimeException("cities.csv: {$city['name']} ({$city['psgc_code']}) has an unknown province or kind.");
            }
        }
        foreach ($barangays as $barangay) {
            if (! isset($cityCodes[$barangay['city_code']])) {
                throw new RuntimeException("barangays.csv: {$barangay['name']} ({$barangay['psgc_code']}) has an unknown city.");
            }
        }
    }

    /**
     * Rows in the table whose codes are no longer in the files: kept, and reported.
     *
     * @param  list<array<string, string>>  $rows
     * @return list<string>
     */
    private function gone(string $table, array $rows): array
    {
        $codes = array_flip(array_column($rows, 'psgc_code'));
        $gone = [];
        foreach (DB::table($table)->orderBy('psgc_code')->get(['psgc_code', 'name']) as $row) {
            $code = self::text($row->psgc_code);
            if (! isset($codes[$code])) {
                $gone[] = "{$table}: ".self::text($row->name)." ({$code})";
            }
        }

        return $gone;
    }

    /** @param  array<array-key, string>  $taken  psgc code => slug (PHP makes numeric codes int keys) */
    private function free(string $base, array $taken): string
    {
        $base = $base === '' ? 'place' : $base;
        $used = array_flip($taken);
        $slug = $base;
        for ($n = 2; isset($used[$slug]); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    /** @return array<string, string> psgc code => slug */
    private function slugs(string $table): array
    {
        $slugs = [];
        foreach (DB::table($table)->get(['psgc_code', 'slug']) as $row) {
            $slugs[self::text($row->psgc_code)] = self::text($row->slug);
        }

        return $slugs;
    }

    /** @return array<string, int> psgc code => id */
    private function ids(string $table): array
    {
        $ids = [];
        foreach (DB::table($table)->get(['psgc_code', 'id']) as $row) {
            $ids[self::text($row->psgc_code)] = (int) self::text($row->id);
        }

        return $ids;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function count(string $table): int
    {
        return DB::table($table)->count();
    }
}
