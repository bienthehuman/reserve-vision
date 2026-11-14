# Build plan

Planned in **review batches**, not weeks. A batch is one or more slices you review and merge together. Slices are ordered by risk and dependency: concurrency first, then isolation, then the screens. Each phase ends with an **automated demo**: a Playwright run that drives the app as each role, plus a release tag.

## Acceptance tests

| # | Scenario | Pass condition | Slice |
| --- | --- | --- | --- |
| T1 | 10 renters reserve the same single-space room for the same dates at once | Exactly 1 hold; 9 get "no space available" | 7 |
| T2 | 6 renters reserve a 4-bed shared room at once | Exactly 4 holds, each on a different bed | 7 |
| T3 | Lease ends Jun 1, next starts Jun 1 | Allowed | 6 |
| T4 | Overlap of a single day | Rejected | 6 |
| T5 | Hold passes its expiry without proof (expiry job not run) | Space reservable again; old hold can't take a proof or be confirmed | 6 |
| T6 | Landlord A requests landlord B's property, room, reservation or proof by ID, on every landlord route | `404`, no data | 8 |
| T7 | Caretaker tries to change a rate or invite a member | `403` | 8 |
| T8 | Renter tries a third open reservation, or a second in the same property | Rejected with a clear message | 6 |
| T8b | One renter fires 3 parallel reserve requests on different properties | At most 2 succeed | 7 |
| T9 | Signed proof URL used by another user, or after it expires | Denied | 14 |
| T10 | Availability search against seeded data | Matches a brute-force check exactly | 13 |
| T11 | Listing page fetched without JavaScript | HTML contains title, price and location; passes axe | 12 |
| T12 | Full flow: search, reserve, upload proof, landlord confirms, both get email | Playwright passes on a mobile viewport | 17 |

**PoC gate:** T1–T8b green on MySQL and MariaDB in CI.

## Phases and slices

Every slice's definition of done includes: tests for the new behaviour pass on both databases, Larastan, Pint and Vitest are clean, the docs are updated, and the PR lists its decisions to check.

### Phase 0: Design (batch 0)

| # | Slice | Definition of done |
| --- | --- | --- |
| 0 | Design docs + ADRs | You accept ARCHITECTURE, DATA-MODEL, ACCESS, RESERVATIONS, CONVENTIONS, this plan and ADRs 0001–0008 |

### Phase 1: Template (batch 1). Tag `v0.1.0`

| # | Slice | Definition of done |
| --- | --- | --- |
| 1 | App skeleton | Laravel + Inertia + React + TypeScript + SSR; Bootstrap Sass theme; dev `compose.yaml` (app, ssr, worker, scheduler, MySQL, Mailpit); `app` and `migrator` DB users; security-headers middleware + test; `/up` health route; design tokens and Bootstrap theme from [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md); dev-only `/styleguide` page; one SSR home page that passes axe |
| 2 | CI | GitHub Actions: Pint, Larastan, Pest on a MySQL 8.4 + MariaDB 11.4 matrix (as `app` user), Vitest, Playwright + axe smoke; badges in the README |
| 3 | Production + Codespaces | `Dockerfile` (multi-stage), `compose.prod.yaml` (Caddy, MinIO), `deploy.sh`, `DEPLOY.md`; CI job that boots the production stack and smoke-tests it; `.devcontainer/` that runs the app with one command |
| — | **Phase demo** | Playwright smoke passes against both the dev stack and the production stack; tag `v0.1.0` |

### Phase 2: Core (batch 2)

| # | Slice | Definition of done |
| --- | --- | --- |
| 4 | Schema | All migrations from DATA-MODEL, with CHECKs, composite FKs and grants; models, enums, factories; a migration test that inserts a cross-landlord room and expects the FK to reject it |
| 5 | Demo data | Seeder: 15 landlords, 60 properties across several cities, about 400 spaces, historical reservations; demo logins (owner, caretaker, renter-1, renter-2); `landlord:verify` command |
| 6 | `ReserveSpace` | Action + `blocking()` scope + renter/room lock order + `BusinessDate`; T3, T4, T5, T8 |
| 7 | Concurrency harness | Minimal reserve endpoint; `tests/Concurrency` driving real parallel HTTP against the Compose stack; T1, T2, T8b on both databases |

### Phase 3: Isolation (batch 3). **PoC gate**, tag `v0.2.0-poc`

| # | Slice | Definition of done |
| --- | --- | --- |
| 8 | Landlord isolation | `CurrentLandlordAccount`, `BelongsToLandlordAccount` scope, policies, 404-across-accounts, audit log writes; permission-matrix doc test; T6 (generated over every landlord route), T7 |
| — | **Phase demo** | Playwright: renter-1 and renter-2 race for the last bed through the minimal UI; owner and caretaker see only their own data; tag `v0.2.0-poc` |

### Phase 4: Landlord side (batch 4)

| # | Slice | Definition of done |
| --- | --- | --- |
| 9 | Dashboard + property/room/space editing | Owner CRUD with archive; caretaker read-only; axe clean |
| 10 | Photos | Upload, queued WebP resize (thumb + full), reorder, delete, processing state |
| 11 | Publish + caretaker invites | Publish rules (verified, a room, a ready photo); invitations with expiry and revoke; remove member; last-owner guard |

### Phase 5: Renter side (batch 5)

| # | Slice | Definition of done |
| --- | --- | --- |
| 12 | Listing pages | `/listings/{city}/{slug}` SSR, OG tags, `schema.org` structured data, `sitemap.xml`, lazy images, Leaflet map; T11 |
| 13 | Search | Filters + availability query (sharing the `blocking()` predicate); `EXPLAIN` + index committed to `docs/perf/`; T10 |
| 14 | Reserve + proof + my reservations | Reserve flow with login intent kept, proof upload, signed URLs, renter cancel; T9 |

### Phase 6: Workflow (batch 6). Tag `v1.0.0-rc`

| # | Slice | Definition of done |
| --- | --- | --- |
| 15 | Landlord decisions | Confirm, reject proof, decline, undo, withdraw, mark moved in, end stay, refund record; transition-table doc test |
| 16 | Viewing requests | Request, accept/decline, done, cancel |
| 17 | Jobs + email | `ExpireHolds`, `EndStays`, proof reminders, every status email (Markdown mailables); T12 on a mobile viewport |
| — | **Phase demo** | Full T12 Playwright run as each role; tag `v1.0.0-rc` |

### Phase 7: Ship (batch 7). Tag `v1.0.0`

| # | Slice | Definition of done |
| --- | --- | --- |
| 18 | Polish + proof | Lighthouse mobile scores recorded; axe pass on every page; README (pitch, GIF, diagram, Guarantees linked to tests, Origins, Next); ADRs Accepted; T2 clip; demo video; Codespaces button checked from a fresh account |

## Decisions log

| Date | Decision | Where |
| --- | --- | --- |
| 2026-11-14 | Deployable, not hosted; CI proves the production stack instead | [ADR 0007](adr/0007-deployable-not-hosted.md) |
| 2026-11-14 | Landlord verification by artisan command; no admin web role | [ACCESS.md](ACCESS.md#actors) |
| 2026-11-14 | Design system: teal primary, terracotta for prices only, Plus Jakarta Sans headings, light mode only in v1 | [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md) |
| 2026-11-14 | Public GitHub repo from day one; no AI attribution in commits or PRs | [CONVENTIONS.md](CONVENTIONS.md#workflow) |

## Backlog

Ideas that came up and are deliberately not in any slice.

| Idea | Source |
| --- | --- |
| Admin web UI for landlord verification | Kickoff |
| Undo "moved in" | RESERVATIONS correction paths |
| Undo a decline | RESERVATIONS correction paths |
| Dark mode | DESIGN-SYSTEM |
| Filipino translation of the UI | DESIGN-SYSTEM |
| Let a landlord user also rent from the same login | DATA-MODEL `users.type` |
| Real payment gateway, monthly billing, chat, reviews, government ID, native apps | Scope document "Out of scope" |
