# ReserveVision

Multi-landlord reservations for apartments and boarding-house beds, with a guarantee that no space is ever double-booked.

> 🚧 Proof of concept: the reservation engine and landlord isolation are built and tested (the PoC gate, slice 3.2). Listings, search and the renter flow are still to come. See the [build plan](docs/BUILD_PLAN.md).

[![Tests (MySQL 8.4)](https://github.com/bienthehuman/reserve-vision/actions/workflows/tests-mysql.yml/badge.svg?branch=main)](https://github.com/bienthehuman/reserve-vision/actions/workflows/tests-mysql.yml)
[![Tests (MariaDB 11.4)](https://github.com/bienthehuman/reserve-vision/actions/workflows/tests-mariadb.yml/badge.svg?branch=main)](https://github.com/bienthehuman/reserve-vision/actions/workflows/tests-mariadb.yml)
[![Quality](https://github.com/bienthehuman/reserve-vision/actions/workflows/quality.yml/badge.svg?branch=main)](https://github.com/bienthehuman/reserve-vision/actions/workflows/quality.yml)
[![E2E](https://github.com/bienthehuman/reserve-vision/actions/workflows/e2e.yml/badge.svg?branch=main)](https://github.com/bienthehuman/reserve-vision/actions/workflows/e2e.yml)
[![Production](https://github.com/bienthehuman/reserve-vision/actions/workflows/production.yml/badge.svg?branch=main)](https://github.com/bienthehuman/reserve-vision/actions/workflows/production.yml)

[![Open in GitHub Codespaces](https://github.com/codespaces/badge.svg)](https://codespaces.new/bienthehuman/reserve-vision)
<!-- GIF of the renter flow on a phone (slice 7.1) -->

## What it does

Renters browse apartments and boarding-house beds, reserve a space and upload proof of the reservation fee. Landlords verify the proof and confirm. Many landlords share one platform, and none can see another's data.

## Guarantees

Each guarantee links to the test that proves it (links filled in as the tests land). T1, T2 and T8b fire **real parallel HTTP requests** at a running multi-threaded server and run in CI on both databases ([![MySQL 8.4](https://github.com/bienthehuman/reserve-vision/actions/workflows/tests-mysql.yml/badge.svg?branch=main)](https://github.com/bienthehuman/reserve-vision/actions/workflows/tests-mysql.yml) [![MariaDB 11.4](https://github.com/bienthehuman/reserve-vision/actions/workflows/tests-mariadb.yml/badge.svg?branch=main)](https://github.com/bienthehuman/reserve-vision/actions/workflows/tests-mariadb.yml), check `… / Concurrency`); each run also proves the requests really overlapped inside the server.

| Guarantee | Proven by |
| --- | --- |
| No space is ever double-booked, even under simultaneous requests | [T1](tests/Concurrency/ReservationRaceTest.php#L114) (10 renters, one room), [T2](tests/Concurrency/ReservationRaceTest.php#L135) (6 renters, 4 beds), [T3, T4](tests/Feature/Reservations/OverlapTest.php) (date edges) |
| A renter can't get past the hold limits with parallel requests | [T8](tests/Feature/Reservations/RenterLimitsTest.php), [T8b](tests/Concurrency/ReservationRaceTest.php#L160) (3 parallel requests, at most 2 holds; 2 in one property, at most 1) |
| An expired hold never blocks a space, even if the cleanup job hasn't run | [T5](tests/Feature/Reservations/ExpiredHoldTest.php) |
| A landlord can't see or probe another landlord's data, on any route; a caretaker can't change rates or invite | [T6](tests/Feature/Isolation/T6CrossAccessTest.php) (generated over every `landlord` and `renter` route: 404, no data, nothing changed), [T7](tests/Feature/Isolation/T7RoleLimitsTest.php) (caretaker gets 403, no audit row), and the [phase 3 demo](e2e/isolation.spec.ts) |
| An invitation link joins exactly one person, and an account never loses its last owner, even under simultaneous requests | [Accept race and remove race](tests/Concurrency/MembersRaceTest.php) (3 sign-ups through one link; 2 owners removing each other), [token guessing](tests/Feature/Invitations/AcceptInvitationTest.php) (one neutral page, rate limited), and the [phase 4 demo](e2e/landlord-phase4.spec.ts) |
| Payment proofs are private; links are short-lived and bound to one user | T9 |

T2 on the dev stack: six renters' requests are all inside the server at once; the room lock lets them through one at a time, so four get beds A to D and two are told there's no space. Each bar is one request, by the server's clock. Run it yourself with `docker compose exec app composer test:concurrency`.

![T2: six parallel reserve requests for a 4-bed room; four holds on four different beds, two rejections](docs/media/t2-race.svg)

The negative controls (the suite run with the locks removed, where it double-books) are in [ADR 0001](docs/adr/0001-room-lock-vs-exclusion-constraint.md#evidence-slice-25).

## A note on naming

In rental language a *tenant* is the person renting; in SaaS language a *tenant* is the customer organization. This codebase avoids the word entirely: **landlord account** (the customer), **landlord member** (owner or caretaker) and **renter**. See [ADR 0005](docs/adr/0005-no-word-tenant.md).

## Design docs

- [Architecture](docs/ARCHITECTURE.md)
- [Data model](docs/DATA_MODEL.md)
- [Access and isolation](docs/ACCESS.md)
- [Reservations: rules, lifecycle, concurrency](docs/RESERVATIONS.md)
- [Design system](docs/DESIGN_SYSTEM.md) ([palette preview](docs/design/preview.html))
- [Conventions](docs/CONVENTIONS.md)
- [Build plan](docs/BUILD_PLAN.md)
- [Architecture decision records](docs/adr/)

## Getting started

Needs Docker with Compose v2. From a fresh clone:

```bash
bin/setup
```

It builds the dev image, installs dependencies, creates the database users, migrates and seeds, then starts the stack at http://localhost:8180 (styleguide at `/styleguide`, mail at http://localhost:8126). It's safe to run again. Ports, test commands and the database users are listed in [CLAUDE.md](CLAUDE.md#environment).

Or, with nothing installed: [open it in GitHub Codespaces](https://codespaces.new/bienthehuman/reserve-vision). The same setup runs inside the codespace and the app opens in the browser when it's ready (a few minutes). The demo logins below work in the codespace too.

## Demo logins

`bin/setup` seeds fake but realistic data (15 landlord accounts, 60 properties in 7 cities, about 390 spaces, about 2,200 reservations in every status). All logins use the password `password`; every email ends in `@example.com`, so nothing can be sent to a real person. In Codespaces (and anywhere `APP_DEMO=true`) the login page lists them in a **Demo accounts** panel: one tap fills in the form. Locally, set `APP_DEMO=true` in `.env` to get the panel.

| Login | Role | Use it for |
| --- | --- | --- |
| `owner@example.com` | Owner of **Santos Family Rentals** (verified, 5 properties) | Landlord flows |
| `caretaker@example.com` | Caretaker in the **same** account | Role limits |
| `owner2@example.com` | Owner of **Cruz Lettings**, a different verified account | Isolation: it must never see the other account's data |
| `renter1@example.com`, `renter2@example.com` | Renters with no reservations | Racing for the last bed |

**The last bed:** the property *Santos Bedspace House* (Barangay 395, Sampaloc, Manila) has a published 4-bed shared room called **Last Bed Room**, on its listing page, [/listings/manila/santos-bedspace-house](http://localhost:8180/listings/manila/santos-bedspace-house) (the old [/rooms/1](http://localhost:8180/rooms/1) redirects to the room on it). Beds A, B and C are confirmed from the 1st of next month, so exactly one bed is free. Log in as `renter1` and `renter2` in two browsers and reserve it for that move-in date at the same moment: one wins and the other is told it's taken. The Playwright test [e2e/reserve.spec.ts](e2e/reserve.spec.ts) does the same, one after the other.

The data is the same on every run (fixed seed), dates are relative to the day you seed, and it is only added to an empty database: running `bin/setup` again changes nothing. To start over: `docker compose down -v && bin/setup`. Three accounts (`tan-realty`, `buenaventura-lettings`, `uy-rentals`) are unverified with draft properties; verify one with `docker compose exec app php artisan landlord:verify tan-realty` (`--list` shows who is waiting, `--revoke` undoes it and unpublishes the account's properties).

## Deploy

ReserveVision is deployable, not hosted: every PR boots the production stack in CI and smoke-tests it ([ADR 0007](docs/adr/0007-deployable-not-hosted.md)). To run it on your own server (one Docker host, automatic HTTPS, MySQL, S3-compatible storage), see [DEPLOY.md](DEPLOY.md).

## Next (out of scope for v1)

- Real payment gateway (renters upload proof instead; [ADR 0003](docs/adr/0003-manual-payment-proof.md))
- Monthly rent billing and receipts after move-in
- Chat, reviews and ratings
- Government ID collection (privacy risk under the Data Privacy Act, no portfolio value)
- Native mobile apps (the web app is mobile-first)
- Admin web UI for landlord verification (a CLI command for now)

## Origins

ReserveVision started as my college thesis in [year]: a reservation system for apartments and boarding houses, built in plain PHP, HTML, Bootstrap and MySQL. It worked, but the scope kept growing and the code had no structure, tests or version control. I hadn't learned Git yet. This is a rebuild, written [X] years later with what I've learned since. It has a narrower scope, explicit rules about who can see what, and tests that prove a bed can't be booked twice.

## License

[MIT](LICENSE)

Place names and codes (every province, city and municipality, and barangay) are from the Philippine Standard Geographic Code of the **Philippine Statistics Authority (PSA)**, 3rd quarter 2026 publication; see [database/data/psgc/README.md](database/data/psgc/README.md).
