# Places: the PSGC

Every province, city and municipality, and barangay in the Philippines, from the **Philippine Standard Geographic Code (PSGC)** of the Philippine Statistics Authority (PSA). Slice 4.4; the tables and rules are in [docs/DATA_MODEL.md](../../../docs/DATA_MODEL.md#places-slice-44).

| | |
| --- | --- |
| Source | PSA, *PSGC Publication Datafile*, 3rd quarter 2026, `PSGC-3Q-2026-Publication-Datafile.xlsx`, from https://psa.gov.ph/classification/psgc |
| Publication date | 30 September 2026 |
| Use constraint | "Acknowledgement of the Philippine Statistics Authority (PSA) as the source" (the workbook's Metadata sheet) |
| Rows | 84 provinces (82, plus Metro Manila and the Special Geographic Area), 1,642 cities and municipalities, 42,010 barangays |
| Size | 1.5 MB in all (`barangays.csv` 1.4 MB) |

## The files

Made by `bin/psgc-convert` from the workbook; don't edit them by hand. Sorted by PSGC code, UTF-8, one header line.

| File | Columns |
| --- | --- |
| `provinces.csv` | `psgc_code`, `name` |
| `cities.csv` | `psgc_code`, `province_code`, `name` (as people write it: `App\Support\Places\PlaceNames`), `kind` (`city` or `municipality`) |
| `barangays.csv` | `psgc_code`, `city_code`, `name`, `district` (Manila only) |

The workbook itself goes in `source/`, which is git-ignored and kept out of the image: download it, don't commit it.

## Updating to a newer publication

The PSA publishes a new datafile every quarter.

1. Download the *PSGC Publication Datafile* from https://psa.gov.ph/classification/psgc (the site asks a browser to pass a check, so `curl` usually can't) into `database/data/psgc/source/`.
2. `docker compose exec app bin/psgc-convert database/data/psgc/source/<file>.xlsx`. It stops with a message, rather than guess, on anything new: a geographic level it doesn't know, a city outside every province that isn't in its `INDEPENDENT_CITIES` list, a barangay without a city.
3. `git diff --stat database/data/psgc` and read the city changes (`git diff database/data/psgc/cities.csv`): new cities, renames, a city that changed province.
4. Update the source and date in the table above, run `docker compose exec app php artisan places:import`, and the Pest test `imports the real files` (its counts change).
5. Deployments pick it up: `deploy.sh` runs `places:import` every time. An import adds and renames, never changes a slug (it is in listing URLs), and keeps a place that left the files (a property may point at it) and lists it.
