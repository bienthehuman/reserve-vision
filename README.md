# ReserveVision

Multi-landlord reservations for apartments and boarding-house beds, with a guarantee that no space is ever double-booked.

> 🚧 Phase 0: design under review. See the [build plan](docs/BUILD_PLAN.md).

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

Each guarantee links to the test that proves it (links filled in as the tests land).

| Guarantee | Proven by |
| --- | --- |
| No space is ever double-booked, even under simultaneous requests | T1–T4, T8b |
| An expired hold never blocks a space, even if the cleanup job hasn't run | T5 |
| A landlord can't see or probe another landlord's data | T6, T7 |
| Payment proofs are private; links are short-lived and bound to one user | T9 |

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

Or, with nothing installed: [open it in GitHub Codespaces](https://codespaces.new/bienthehuman/reserve-vision). The same setup runs inside the codespace and the app opens in the browser when it's ready (a few minutes). Demo logins arrive with the demo data in slice 2.2.

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
