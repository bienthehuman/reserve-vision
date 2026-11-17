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
| 4.3 | Publish + caretaker invites | Publish rules (verified, a room with a space, a ready photo, payment instructions) as a checklist; invitation email, acceptance, expiry, resend and revoke (creation exists from 3.2); remove member; last-owner guard |
| 4.4 | Places | Every province, city and barangay from the PSA's PSGC list, imported into our own tables (no live API); the property form's address becomes dependent dropdowns; unique city slugs for listing URLs, the seven existing ones unchanged. Added after the UI review, before 5.1's search by place |

| — | **Phase demo** | Playwright ([e2e/landlord-phase4.spec.ts](../e2e/landlord-phase4.spec.ts), phone viewport, both stacks): the owner creates a property with a shared room, uploads a photo, sees the checklist complete and publishes, and the room page opens; invites a caretaker, who joins through the emailed link (Mailpit; on the production stack a link from `landlord:invite`) and sees read-only pages; the owner removes them and their next request is `403`. Tag `v0.4.0` after 4.4 |

### Phase 5: Renter side (batch 5). Tag `v0.5.0`

| # | Slice | Definition of done |
| --- | --- | --- |
| 5.1 | Listing pages | `/listings/{city}/{slug}` SSR, OG tags, `schema.org` structured data, `sitemap.xml`, `robots.txt`, lazy images, address with an "Open in Maps" link (no map in v1), featured listings on the home page and a plain `/listings` list; replaces the 2.5 room page; T11 |
| 5.2 | Search | Filters + availability query (sharing the `blocking()` predicate); `EXPLAIN` + index committed to `docs/perf/` (no index was needed); T10 |
| 5.3 | Reserve + proof + my reservations | Reserve flow with login intent kept, proof upload, signed URLs, renter cancel; T9 |
| — | **Phase demo** | Playwright ([e2e/renter-phase5.spec.ts](../e2e/renter-phase5.spec.ts), phone viewport, both stacks; on the production stack the proof goes through MinIO): renter2 reserves a room and cancels it; a guest searches Baguio, opens the owner's listing, presses Reserve, logs in as renter2, comes back to the same room and stay, and lands on the hold page; uploads a proof and sees "Awaiting review"; the owner opens the reservation and sees the proof; renter1 gets `404` on the proof link and `403` on the owner's signed URL; zero axe violations. Tag `v0.5.0` |

### Phase 6: Workflow (batch 6). Tag `v1.0.0-rc`

| # | Slice | Definition of done |
| --- | --- | --- |
| 6.1 | Landlord decisions | Confirm, reject proof, decline, undo, withdraw, mark moved in, end stay, refund record |
| 6.2 | Viewing requests | Request, accept/decline, done, cancel |
| 6.3 | Jobs + email | `ExpireHolds`, `EndStays`, proof reminders, every status email (Markdown mailables); T12 on a mobile viewport |
| — | **Phase demo** | Full T12 Playwright run as each role; tag `v1.0.0-rc`. **Done in 6.3** (`e2e/t12-full-flow.spec.ts`, both stacks); the tag follows the merge |

### Phase 7: Ship (batch 7). Tag `v1.0.0`. **Done**

| # | Slice | Definition of done |
| --- | --- | --- |
| 7.1 | CC0 demo photos | ~24 public-domain interior photos with recorded sources, seeded through the photo pipeline |
| 7.2 | Performance + accessibility | Lighthouse (mobile) in CI with assertions and recorded scores; axe on every page, generated from the route list; JS budget |
| 7.3 | README, ADRs, release | README (pitch, GIF, diagram, Guarantees linked to tests, Origins, Out of scope), ADRs reviewed and Accepted, backlog groomed; tag `v1.0.0` |
| — | **Phase demo** | **Done in 7.3**: the README's renter-flow GIF, recorded by a script against the production stack (`make media`); every check green; tag `v1.0.0` after the merge |

## Decisions log

| Date | Decision | Where |
| --- | --- | --- |
| 2026-11-17 | Release `v1.0.0` after slice 7.3: every phase done, the ADRs reviewed against the code and Accepted (dated updates where reality changed), ADRs 0009–0013 written for decisions made during the build, the backlog groomed | [adr/README.md](adr/README.md) |
| 2026-11-17 | The README GIF is recorded by a script (`e2e/media/renter-flow.ts`, `make media`) against the production stack, not by hand, so it can be re-recorded after UI changes | [CLAUDE.md](../CLAUDE.md#readme-media-slice-73) |
| 2026-11-17 | Phase 7 is three slices (7.1 CC0 photos, 7.2 performance and accessibility, 7.3 README and release). Real CC0 / public-domain photos replace the generated ones; each is recorded with its source page, author and licence, and the licence is checked against an allowlist by a test | [CREDITS.md](../database/data/demo-photos/CREDITS.md) |
| 2026-11-17 | No demo video: the README GIF and the T2 clip are enough | this file |
| 2026-11-17 | The README's "Next" heading becomes "Out of scope" | this file |
| 2026-11-14 | Deployable, not hosted; CI proves the production stack instead | [ADR 0007](adr/0007-deployable-not-hosted.md) |
| 2026-11-14 | Landlord verification by artisan command; no admin web role | [ACCESS.md](ACCESS.md#actors) |
| 2026-11-14 | Design system: ube primary (also prices), mango highlight, Plus Jakarta Sans headings; hue chosen to differ from Hilom/PesoTrack teal and the portfolio's orange, light mode only in v1 | [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md) |
| 2026-11-17 | Slice 6.1: landlord decisions take only the reservation row lock (no landlord move makes a free space blocked, so the room lock isn't needed); a decline rejects the pending proof with the decline reason; an undo clears `decided_by`/`decided_at`; every time guard lives in `Reservation::assertCanMoveTo()` | [RESERVATIONS.md](RESERVATIONS.md#transition-table) |
| 2026-11-17 | Slice 5.3: payment proofs are streamed through PHP after three checks (signature and expiry, the logged-in user named in the URL, the policy again), never served by S3 pre-signed URLs; the bucket stays unreachable from outside. Proofs are kept byte for byte (no re-encoding) and judged by content; the download carries `Content-Security-Policy: sandbox` | [ACCESS.md](ACCESS.md#private-files) |
| 2026-11-17 | No map in v1: the listing shows the address and an "Open in Maps" link that searches the address text. No map script, tiles or coordinates | [ARCHITECTURE.md](ARCHITECTURE.md#shape) |
| 2026-11-16 | Slice 4.4 built: the PSGC 3Q 2026 publication as three CSVs in the repo, imported by `places:import`; properties link to a city and barangay and keep their text columns as copies. Metro Manila and BARMM's Special Geographic Area are province rows; independent cities go under the province whose PSGC row excludes them | [DATA_MODEL.md](DATA_MODEL.md#places-slice-44) |
| 2026-11-16 | Slice 4.4 added: places from the PSGC list, bundled rather than a live API, before 5.1 so search uses the same list. Tag `v0.4.0` moves to after 4.4 | this file |
| 2026-11-16 | UI review after 4.2: design system retuned (surface layer, one control height and radius, link styles, account menu, pager), addresses standardized as street / barangay / city / province, `lat`/`lng` dropped. 4.3's publish rule no longer asks for coordinates | [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md), [DATA_MODEL.md](DATA_MODEL.md) |
| 2026-11-16 | Phase 3 split into 3.1 (isolation core) and 3.2 (minimal landlord pages + T6/T7): T6 and T7 need landlord routes, and none existed. T6 also covers renter routes | this file |
| 2026-11-15 | Auth gets its own slice (2.4), before the concurrency harness (now 2.5), which needs logged-in renters. Phase 2 ends with tag `v0.2.0`; the PoC tag becomes `v0.3.0-poc` | this file |
| 2026-11-15 | Transition-table doc test moves from 6.1 to 2.1, so the lifecycle is locked before any action uses it | this file |
| 2026-11-14 | Keep Inertia + React with SSR after comparing Blade + Livewire and a Blade/Inertia hybrid | [ADR 0002](adr/0002-inertia-monolith.md) |
| 2026-11-14 | Hilom conventions: `feat/<phase>.<slice>-name` branches, phase.slice numbering, `UPPER_SNAKE.md` doc names | [CONVENTIONS.md](CONVENTIONS.md#workflow) |
| 2026-11-14 | Public GitHub repo from day one; no AI attribution in commits or PRs | [CONVENTIONS.md](CONVENTIONS.md#workflow) |

## Backlog

Ideas that came up and are deliberately not in any slice, one sentence each with where it came from. Groomed for v1 in slice 7.3: grouped, duplicates merged, done rows removed.

### Renter

| Idea | Source |
| --- | --- |
| Let a renter replace a proof while it waits for review (today they cancel, or wait for the landlord to ask for a new one) | Slice 5.3 |
| Upload progress on the proof form, and a client-side check of the 5 MB limit before sending | Slice 5.3 |
| A "Continue to your room" link on the "confirm your email" page, for a first-time sign-up through Reserve who confirms on another device | Slice 5.3 |
| Show the renter which member decided (today they see the landlord account's name only) | Slice 6.1 |
| Free beds update as the move-in date changes (a partial reload enhancing the plain "Check free beds" form) | Slice 5.1 |
| Full-text search (property names, house rules) and saved searches | Slice 5.2 |
| A map, on the listing page and for search results, with the pin from geocoding or a "drop a pin" picker, never typed coordinates ([ADR 0009](adr/0009-no-map-in-v1.md)) | Slice 4.1, 5.2, UI review |
| The home page's "Where" lists only cities with listings; a province-aware combobox would reach every place | Slice 5.2 |
| Barangay in the search link by PSGC code instead of id, so a shared link survives a reimport into an empty database | Slice 5.2 |
| Login, logout and sign-up without JavaScript (plain form posts with a `_token`), like the listing and search pages | Slice 2.4 |
| Profile and settings pages: change name, phone, email (re-verified) and password, with `User` and `LandlordAccount` policies and matrix rows | Slice 2.4, 3.1 |
| Two-factor authentication and passkeys (Fortify supports both; the routes are off) | Slice 2.4 |
| Social login (Google, Facebook) | Slice 2.4 |
| Filipino translation of the UI, which first needs the frontend strings to go through translation helpers (today only server-side strings use `__()`) | DESIGN_SYSTEM, slice 1.1 |
| Dark mode | DESIGN_SYSTEM |
| SMS or push notifications, digest emails, and calendar invites for viewings | Slice 6.3 |

### Landlord

| Idea | Source |
| --- | --- |
| Undo a decline within a short window if the space is still free (today a mistaken decline is final; the renter reserves again) | RESERVATIONS correction paths, slice 6.1 |
| Undo "moved in" | RESERVATIONS correction paths |
| Decide what happens to a `confirmed` reservation whose stay passed without "moved in" (no-shows; the job leaves it as it is) | Slice 6.3 |
| Common reasons to pick from when asking for a new proof ("The amount is wrong", "I can't read it"), next to the free text | Slice 6.1 |
| A reservation's history on its page (who confirmed, rejected, undid, and when), read from the audit log | Slice 6.1 |
| Search the landlord's reservations by renter name or room, and filter by date (the demo owner has over 300) | Slice 3.2 |
| Per-member email preferences (who gets which mail) | Slice 6.3 |
| Invite a co-owner (the `role` column already allows `owner`), and change a member's role without removing and inviting again | Slice 4.3 |
| Let a member leave an account themself | Slice 4.3 |
| Tell the owner by email when an invitation is accepted | Slice 4.3 |
| Show who was removed from the account and when (the audit log has it) | Slice 4.3 |
| After "Log out" on the invitation page, come back to the invitation instead of the home page | Slice 4.3 |
| Show a caretaker's email on the members page, if owners ask for it | Slice 3.2 |
| A preview of the listing before publishing | Slice 4.3 |
| Let an owner fix a generated listing link (`-2`) before publishing | UI review |
| Change a published property's slug and keep the old URL working with a `301` | Slice 4.1 |
| Delete a room or space that was never reserved (today only archive) | Slice 4.1 |
| Reorder or bulk-rename beds, and add several at once | Slice 4.1 |
| Show which spaces block archiving, and by whom, before the owner clicks | Slice 4.1 |
| Tell an owner when a places import leaves their property without a linked barangay (today only the publish checklist shows it) | Slice 4.4 |
| HEIC/HEIF photo uploads (iPhones' default) | Slice 4.2 |
| Photos per room ("Bed area of Room 2"), not only per property | Slice 4.2 |
| Upload progress per photo, and drag-and-drop next to the Up and Down buttons | Slice 4.2 |
| Say why a photo failed (unreadable file or storage), and retry a storage failure without choosing the file again | Slice 4.2 |
| A styled picker for date fields (the browser's is used; dropdown lists are styled in Chromium) | UI review |

### Operations

| Idea | Source |
| --- | --- |
| An admin web UI for landlord verification, for more than one operator ([ADR 0013](adr/0013-landlord-verification-by-artisan-command.md)) | Kickoff |
| An audit row for the system's own moves (expired holds, ended stays) | Slice 6.3 |
| A retention policy for personal data: delete proof files when a reservation's data is purged (kept forever today) | Slice 5.3 |
| Sweep temporary photo uploads (`uploads/tmp/` on the proofs disk) older than a day, left by a worker killed mid-job | Slice 4.2 |
| `deploy.sh` takes a database backup before migrating | Slice 1.3 |
| Lowercase emails on save everywhere (Fortify lowercases at login and registration only) | Slice 2.4 |
| Fix the demo street addresses, which repeat the barangay and city already shown after them (changes the seeder's hash and the e2e texts) | Slice 5.1 |

### Quality

| Idea | Source |
| --- | --- |
| Fix a focus race from 7.2: after a post that navigates (login), `app.tsx` focuses `main h1` 150 ms later, and that `focusin` closes an account menu opened in between (`auth.spec.ts` "demo user … logs in" failed on PR #37). Skip the move when focus has changed since the visit | Slice 7.3 |
| Fix the flaky `SecurityHeadersTest`: it writes `public/hot` for a moment, and a parallel Pest worker then renders SSR against the Vite dev server that CI doesn't run (failed `MariaDB 11.4 / Pest` on `main` after #36) | Slice 7.3 |
| Fix a flaky concurrency fixture: two Faker emails collided in the committed fixtures (`users_email_unique`, failed `MySQL 8.4 / Concurrency` on `main` after #35); use unique emails | Slice 7.3 |
| Make the Phase 5 search e2e (Quezon City, today + 14 days) independent of the day the database was seeded: pick the place and date from the data, or have the seed keep a free window | Slice 7.1 |
| Answer a reservation that deadlocks on all 3 attempts as "busy, try again" (`503` + `Retry-After`, or the `error` flash) instead of a bare `500` | Slice 2.5 |
| Extend the scope arch test to raw `DB::select()` / `DB::statement()` naming a landlord-owned table (only `DB::table()` is caught) | Slice 3.1 |
| Factories that work while a landlord is logged in (fixtures must be created before `actingAs()`) | Slice 3.1 |
| Narrow the "status is written only in `App\Actions`" arch test to reservations if photo and proof status writes make it noisy | Slice 2.1 |
| Cover the invitation page's usable states in the accessibility sweep (needs a live invitation link; the sweep covers the neutral `404`) | Slice 7.2 |
| Real screen-reader passes (NVDA, VoiceOver, TalkBack) on the reserve and decision flows ([perf/accessibility.md](perf/accessibility.md)) | Slice 7.2 |
| Remove `resources/js/Pages/Rooms/Show.tsx`, the 2.5 room page that nothing renders since 5.1 made `/rooms/{room}` a redirect | Slice 7.3 |
| Check by hand how desktop browsers open a PDF proof served with `Content-Security-Policy: sandbox`, and render a first-page preview if landlords want PDFs inline | Slice 5.3 |

### Performance

| Idea | Source |
| --- | --- |
| A mid-size photo variant (about 960 px WebP) for the listing cover on phones: about 120 kB less on the slowest page, with a backfill for existing photos | Slice 7.2 |
| Run Lighthouse once against a deployment with TLS (CI skips `is-on-https` and `redirects-http`, and doesn't measure HTTP/2) | Slice 7.2 |

### Infrastructure

| Idea | Source |
| --- | --- |
| A smaller production image: the FrankenPHP bookworm base is most of it (Alpine variant or a static FrankenPHP build) | Slice 1.3 |
| Cache the production image build in CI (Buildx with the GitHub Actions cache) | Slice 1.3 |
| CSP `img-src` for a photos origin other than the app (`AWS_PHOTOS_URL` on an external S3 or CDN) | Slice 1.3, 4.2 |
| The Vite dev server (HMR) in Codespaces; the dev container uses built assets | Slice 1.3 |
| Drop the `brianium/paratest` `<7.28` conflict in composer.json once Pest supports ParaTest 7.28 | Slice 1.2 |
| Header nav as `Navbar` + `Offcanvas` once there are more links than the account menu | Slice 2.4 |
