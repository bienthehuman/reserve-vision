# ReserveVision

Multi-landlord reservations for apartments and boarding-house beds, with a guarantee that no space is ever double-booked.

> 🚧 Phase 0: design under review. See the [build plan](docs/BUILD_PLAN.md).

<!-- Badges: MySQL CI, MariaDB CI (slice 1.2) -->
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

_The Codespaces button arrives in slice 1.3._

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
