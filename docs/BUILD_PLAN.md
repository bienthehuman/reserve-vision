# Build plan

Planned in **review batches**, not weeks. A batch is one or more slices you review and merge together. Slices are ordered by risk and dependency: concurrency first, then isolation, then the screens. Each phase ends with an **automated demo**: a Playwright run that drives the app as each role, plus a release tag.

## Acceptance tests

| # | Scenario | Pass condition | Slice |
| --- | --- | --- | --- |
| T1 | 10 renters reserve the same single-space room for the same dates at once | Exactly 1 hold; 9 get "no space available" | 2.5 |
| T2 | 6 renters reserve a 4-bed shared room at once | Exactly 4 holds, each on a different bed | 2.5 |
| T3 | Lease ends Jun 1, next starts Jun 1 | Allowed | 2.3 |
| T4 | Overlap of a single day | Rejected | 2.3 |
| T5 | Hold passes its expiry without proof (expiry job not run) | Space reservable again; old hold can't take a proof or be confirmed | 2.3 |
| T6 | Landlord A requests landlord B's property, room, reservation or proof by ID, on every landlord route; renter A requests renter B's reservation | `404`, no data | 3.2 (generated over every route, so later routes are covered automatically) |
| T7 | Caretaker tries to change a rate or invite a member | `403` | 3.2 |
| T8 | Renter tries a third open reservation, or a second in the same property | Rejected with a clear message | 2.3 |
| T8b | One renter fires 3 parallel reserve requests on different properties | At most 2 succeed | 2.5 |
| T9 | Signed proof URL used by another user, or after it expires | Denied | 5.3 |
| T10 | Availability search against seeded data | Matches a brute-force check exactly | 5.2 |
| T11 | Listing page fetched without JavaScript | HTML contains title, price and location; passes axe | 5.1 |
| T12 | Full flow: search, reserve, upload proof, landlord confirms, both get email | Playwright passes on a mobile viewport | 6.3 |

**PoC gate:** T1–T8b green on MySQL and MariaDB in CI.

## Phases and slices

Every slice's definition of done includes: tests for the new behaviour pass on both databases, Larastan, Pint and Vitest are clean, the docs are updated, and the PR lists its decisions to check.

### Phase 0: Design (batch 0)

| # | Slice | Definition of done |
| --- | --- | --- |
| 0.1 | Design docs + ADRs | You accept ARCHITECTURE, DATA_MODEL, ACCESS, RESERVATIONS, CONVENTIONS, this plan and ADRs 0001–0008 |

### Phase 1: Template (batch 1). Tag `v0.1.0`

| # | Slice | Definition of done |
| --- | --- | --- |
| 1.1 | App skeleton | Laravel + Inertia + React + TypeScript + SSR; Bootstrap Sass theme; dev `compose.yaml` (app, ssr, worker, scheduler, MySQL, Mailpit); `app` and `migrator` DB users; security-headers middleware + test; `/up` health route; design tokens and Bootstrap theme from [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md); dev-only `/styleguide` page; one SSR home page that passes axe |
| 1.2 | CI | GitHub Actions: Pint, Larastan, Pest on a MySQL 8.4 + MariaDB 11.4 matrix (as `app` user), Vitest, Playwright + axe smoke; badges in the README |
| 1.3 | Production + Codespaces | `Dockerfile` (multi-stage), `compose.prod.yaml` (Caddy, MinIO), `deploy.sh`, `DEPLOY.md`; CI job that boots the production stack and smoke-tests it; `.devcontainer/` that runs the app with one command |
| — | **Phase demo** | Playwright smoke passes against both the dev stack and the production stack; tag `v0.1.0` |

### Phase 2: Core (batch 2). Tag `v0.2.0`

| # | Slice | Definition of done |
| --- | --- | --- |
| 2.1 | Schema | All migrations from DATA_MODEL, with CHECKs, composite FKs and the append-only `audit_logs` grant; models, enums (with the reservation transition table), factories; a migration test that inserts a cross-landlord room and expects the FK to reject it; transition-table doc test |
| 2.2 | Demo data | Seeder: 15 landlords, 60 properties across several cities, about 400 spaces, historical reservations; demo logins (owner, caretaker, renter-1, renter-2); `landlord:verify` command |
| 2.3 | `ReserveSpace` | Action + `blocking()` scope + renter/room lock order + `BusinessDate`; T3, T4, T5, T8 |
| 2.4 | Auth | Login, logout, registration as renter or landlord (landlord registration creates the landlord account + owner membership), password reset and email verification via Mailpit, rate limits, intended-URL redirect; demo-login panel when `APP_DEMO=true`; production smoke logs in |
| 2.5 | Concurrency harness | Minimal room page + reserve endpoint; `tests/Concurrency` driving real parallel HTTP against a running server; T1, T2, T8b on both databases in CI |
| — | **Phase demo** | Concurrency suite green on MySQL and MariaDB; Playwright logs in as each demo user and renter-1 reserves through the minimal room page; tag `v0.2.0` |

### Phase 3: Isolation (batch 3). **PoC gate**, tag `v0.3.0-poc`

| # | Slice | Definition of done |
| --- | --- | --- |
| 3.1 | Isolation core | `CurrentLandlordAccount`, fail-closed `BelongsToLandlordAccount` scope, policies for every model, `AuditLogger`, renter ownership via policies; permission-matrix doc test |
| 3.2 | Landlord surface + T6/T7 | Minimal landlord pages: reservations list and detail (renter contact shown only here), properties list and detail, change a room's rate (owner), create a caretaker invitation (owner; email and acceptance in 4.3); every write audited; T6 generated over every landlord and renter route; T7 |
| — | **Phase demo** | Playwright: renter-1 and renter-2 race for the last bed; owner and caretaker see only their own account's reservations, owner2 gets 404 on them; caretaker gets 403 changing a rate; tag `v0.3.0-poc` |

### Phase 4: Landlord side (batch 4)

| # | Slice | Definition of done |
| --- | --- | --- |
| 4.1 | Dashboard + property/room/space editing | Real dashboard; owner CRUD with archive, extending 3.2's pages; caretaker read-only; axe clean |
| 4.2 | Photos | Upload, queued WebP resize (thumb + full), reorder, delete, processing state |
| 4.3 | Publish + caretaker invites | Publish rules (verified, a room, a ready photo); invitation email, acceptance, expiry and revoke (creation exists from 3.2); remove member; last-owner guard |

### Phase 5: Renter side (batch 5)

| # | Slice | Definition of done |
| --- | --- | --- |
| 5.1 | Listing pages | `/listings/{city}/{slug}` SSR, OG tags, `schema.org` structured data, `sitemap.xml`, lazy images, Leaflet map; T11 |
| 5.2 | Search | Filters + availability query (sharing the `blocking()` predicate); `EXPLAIN` + index committed to `docs/perf/`; T10 |
| 5.3 | Reserve + proof + my reservations | Reserve flow with login intent kept, proof upload, signed URLs, renter cancel; T9 |

### Phase 6: Workflow (batch 6). Tag `v1.0.0-rc`

| # | Slice | Definition of done |
| --- | --- | --- |
| 6.1 | Landlord decisions | Confirm, reject proof, decline, undo, withdraw, mark moved in, end stay, refund record |
| 6.2 | Viewing requests | Request, accept/decline, done, cancel |
| 6.3 | Jobs + email | `ExpireHolds`, `EndStays`, proof reminders, every status email (Markdown mailables); T12 on a mobile viewport |
| — | **Phase demo** | Full T12 Playwright run as each role; tag `v1.0.0-rc` |

### Phase 7: Ship (batch 7). Tag `v1.0.0`

| # | Slice | Definition of done |
| --- | --- | --- |
| 7.1 | Polish + proof | Lighthouse mobile scores recorded; axe pass on every page; README (pitch, GIF, diagram, Guarantees linked to tests, Origins, Next); ADRs Accepted; T2 clip; demo video; Codespaces button checked from a fresh account |

## Decisions log

| Date | Decision | Where |
| --- | --- | --- |
| 2026-11-14 | Deployable, not hosted; CI proves the production stack instead | [ADR 0007](adr/0007-deployable-not-hosted.md) |
| 2026-11-14 | Landlord verification by artisan command; no admin web role | [ACCESS.md](ACCESS.md#actors) |
| 2026-11-14 | Design system: ube primary (also prices), mango highlight, Plus Jakarta Sans headings; hue chosen to differ from Hilom/PesoTrack teal and the portfolio's orange, light mode only in v1 | [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md) |
| 2026-11-16 | UI review after 4.2: design system retuned (surface layer, one control height and radius, link styles, account menu, pager), addresses standardized as street / barangay / city / province, `lat`/`lng` dropped. 4.3's publish rule no longer asks for coordinates | [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md), [DATA_MODEL.md](DATA_MODEL.md) |
| 2026-11-16 | Phase 3 split into 3.1 (isolation core) and 3.2 (minimal landlord pages + T6/T7): T6 and T7 need landlord routes, and none existed. T6 also covers renter routes | this file |
| 2026-11-15 | Auth gets its own slice (2.4), before the concurrency harness (now 2.5), which needs logged-in renters. Phase 2 ends with tag `v0.2.0`; the PoC tag becomes `v0.3.0-poc` | this file |
| 2026-11-15 | Transition-table doc test moves from 6.1 to 2.1, so the lifecycle is locked before any action uses it | this file |
| 2026-11-14 | Keep Inertia + React with SSR after comparing Blade + Livewire and a Blade/Inertia hybrid | [ADR 0002](adr/0002-inertia-monolith.md) |
| 2026-11-14 | Hilom conventions: `feat/<phase>.<slice>-name` branches, phase.slice numbering, `UPPER_SNAKE.md` doc names | [CONVENTIONS.md](CONVENTIONS.md#workflow) |
| 2026-11-14 | Public GitHub repo from day one; no AI attribution in commits or PRs | [CONVENTIONS.md](CONVENTIONS.md#workflow) |

## Backlog

Ideas that came up and are deliberately not in any slice.

| Idea | Source |
| --- | --- |
| Admin web UI for landlord verification | Kickoff |
| Undo "moved in" | RESERVATIONS correction paths |
| Undo a decline | RESERVATIONS correction paths |
| Dark mode | DESIGN_SYSTEM |
| Filipino translation of the UI | DESIGN_SYSTEM |
| Drop the `brianium/paratest` `<7.28` conflict in composer.json once Pest supports ParaTest 7.28 | Slice 1.2 |
| Frontend strings through translation helpers (today only server-side strings use `__()`) | Slice 1.1 |
| Smaller production image: the FrankenPHP bookworm base is 805 MB of the 1.09 GB, mostly compilers it keeps. Options: the Alpine variant (musl, differs from dev) or a static FrankenPHP build | Slice 1.3 |
| `deploy.sh` takes a database backup before migrating | Slice 1.3 |
| Cache the production image build in CI (Buildx with the GitHub Actions cache) | Slice 1.3 |
| Make the Vite dev server (HMR) work in Codespaces; today the dev container uses built assets | Slice 1.3 |
| CSP `img-src` for a photos origin other than the app (external S3 or CDN: `AWS_PHOTOS_URL`). Photos are same-origin (`/media/photos`) with MinIO, so 4.2 didn't need it | Slice 1.3, 4.2 |
| Trim the entry chunk (`app.js`, 112 KB gzipped, mostly React DOM + Inertia) and add a JS budget next to the CSS budget | Slice 1.1 build output |
| Seed viewing requests | Slice 2.2 |
| Narrow the "status is written only in `App\Actions`" arch test to reservations if it gets noisy for `PropertyPhoto`/`PaymentProof` status writes | Slice 2.1 |
| Two-factor authentication and passkeys (Fortify supports both; the routes are off) | Slice 2.4 |
| Profile and settings pages: change name, phone, email (re-verify) and password | Slice 2.4 |
| Social login (Google, Facebook) | Slice 2.4 |
| Login, logout and sign-up without JavaScript (plain form posts with a `_token` field); today the forms need hydration | Slice 2.4 |
| Header nav as `Navbar` + `Offcanvas` once there are more than the account links | Slice 2.4 |
| Lowercase emails on save everywhere (today Fortify lowercases at login and registration only) | Slice 2.4 |
| A reservation transaction that deadlocks on all 3 attempts is a bare `500`. Answer it as a domain-style "busy, try again" (`503` + `Retry-After`, or the `error` flash) instead. Seen only in the 2.5 negative controls (no room lock), but neighbouring rooms can deadlock under load (ADR 0001) | Slice 2.5 |
| Make `flash.error` dismissible and focus it after a failed post (2.5 renders it as a plain `role="alert"` alert) | Slice 2.5 |
| Extend the scope arch test to raw `DB::select()` / `DB::statement()` naming a landlord-owned table (today only `DB::table()` is caught) | Slice 3.1 |
| Policies for `LandlordAccount` (account settings) and `User` (profile) once those pages exist, with matrix rows | Slice 3.1 |
| Factories that work while a landlord is logged in (today fixtures must be created before `actingAs()`, or the write guard refuses them) | Slice 3.1 |
| Search reservations by renter name or room, and a date filter, on the landlord list (the demo owner has 328, so finding one means paging) | Slice 3.2 |
| Revoke an invitation and resend from the members page (the policy ability `revoke` exists; the UI is 4.3) | Slice 3.2 |
| Show a caretaker's email on the members page, if owners ask for it (today names only) | Slice 3.2 |
| A map on the listing page. `lat`/`lng` were dropped (review after 4.2): if a map comes back, place the pin by geocoding the address or with a "drop a pin" picker, never typed coordinates | Slice 4.1, UI review |
| More cities, and provinces beyond the seven in `database/data/places.php` (a province → city → barangay list such as the PSGC) | UI review |
| Let an owner fix an ugly generated listing link (`-2`) before publishing; the form field was removed as too technical | UI review |
| A styled list for date fields (the dropdown list is styled in Chromium via `appearance: base-select`; the date picker is still the browser's) | UI review |
| Redirect old slugs: let a published property's slug change and keep the old URL working with a 301 | Slice 4.1 |
| Delete a room or space that was never reserved (today only archive) | Slice 4.1 |
| Reorder or bulk-rename beds; a bed count change that adds several at once | Slice 4.1 |
| Show on the property page which spaces are blocked from archiving, and by whom, before the owner clicks | Slice 4.1 |
| Barangay as a select from the city's list, with "other" | Slice 4.1 |
| Replace generated demo photos with a licensed (CC0) photo set | Slice 4.2 |
| HEIC/HEIF uploads (iPhones' default format; today the owner has to export a JPEG) | Slice 4.2 |
| Sweep temporary uploads (`uploads/tmp/` on the proofs disk) older than a day: a worker killed at the wrong moment can leave one behind | Slice 4.2 |
| Retry a failed photo without choosing the file again, when the failure was storage and not the file (today the upload is deleted on failure) | Slice 4.2 |
| Upload progress per file, and drag-and-drop to reorder next to the Up and Down buttons | Slice 4.2 |
| Photos per room ("Bed area of Room 2"), not only per property | Slice 4.2 |
| Tell the owner why a photo failed (unreadable file or storage) instead of one message | Slice 4.2 |
