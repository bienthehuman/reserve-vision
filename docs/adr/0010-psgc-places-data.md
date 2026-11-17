# 0010. Use the PSA's place list (PSGC), bundled, instead of free-text addresses

- Status: Accepted (2026-11-17)
- Date: 2026-11-16 (slice 4.4)

## Context

Until slice 4.4 a property's address was free text: a city chosen from seven, and a typed barangay. Search by place (5.2) needs every listing in a barangay to be found by the same name, listing URLs need a stable city slug, and the seven cities were a demo shortcut, not a product. The Philippine Statistics Authority publishes the **Philippine Standard Geographic Code** (PSGC) every quarter: every province, city and municipality, and barangay, with a stable code each, about 42,000 barangays in all.

## Options

1. **Keep free text**, with suggestions: no data to maintain, but "Brgy. 395", "Barangay 395" and "395, Sampaloc" are three places to a search.
2. **A live PSGC API** (a community-run one): always current, but a third-party dependency on the property form, and on every search, with its own uptime and rate limits; nothing works offline (Codespaces, CI).
3. **The PSGC bundled with the code**: the PSA's workbook converted to three CSV files in the repo and imported into our own tables (`provinces`, `cities`, `barangays`) by `php artisan places:import`.

## Decision

Option 3. A property links to a city and a barangay by id and keeps text copies of the names (for display, and for properties from before 4.4). The property form's address is three dependent comboboxes (province, city, barangay), fed by two cached JSON endpoints. Names are written the way people write them ("Makati", "Cebu City"), and city slugs are unique nationwide and never change once given.

## Consequences

- Good: every listing in a barangay is found by one name; search filters by city and barangay id.
- Good: no network dependency; `bin/setup`, `deploy.sh` and CI import it in about 2 seconds.
- Good: the PSA's code is the key, so a newer publication adds and renames places without breaking links or URLs; nothing is ever deleted (a property may point at it).
- Bad: 1.5 MB of CSV in the repo, and updating it is a manual step each quarter (download in a browser, the PSA's site blocks scripts; run `bin/psgc-convert`; review the diff). Steps: [database/data/psgc/README.md](../../database/data/psgc/README.md).
- Bad: the PSGC has quirks the converter must know: 18 cities outside every province, Manila's districts, BARMM's Special Geographic Area, footnote markers in names. It stops on anything new rather than guess.
- Attribution: the PSA asks to be acknowledged as the source; the README and the data's README do.
- Details: [DATA_MODEL.md](../DATA_MODEL.md#places-slice-44).
