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

### Phase 7: Ship (batch 7). Tag `v1.0.0`

| # | Slice | Definition of done |
| --- | --- | --- |
| 7.1 | CC0 demo photos | ~24 public-domain interior photos with recorded sources, seeded through the photo pipeline |
| 7.2 | Performance + accessibility | Lighthouse (mobile) in CI with assertions and recorded scores; axe on every page, generated from the route list; JS budget |
| 7.3 | README, ADRs, release | README (pitch, GIF, diagram, Guarantees linked to tests, Origins, Out of scope), ADRs reviewed and Accepted, backlog groomed; tag `v1.0.0` |

## Decisions log

| Date | Decision | Where |
| --- | --- | --- |
| 2026-11-17 | Phase 7 is three slices (7.1 CC0 photos, 7.2 performance and accessibility, 7.3 README and release). Real CC0 / public-domain photos replace the generated ones; each is recorded with its source page, author and licence, and the licence is checked against an allowlist by a test | [CREDITS.md](../database/data/demo-photos/CREDITS.md) |
| 2026-11-17 | No demo video: the README GIF and the T2 clip are enough | this file |
| 2026-11-17 | The README's "Next" heading becomes "Out of scope" | this file |
| 2026-11-14 | Deployable, not hosted; CI proves the production stack instead | [ADR 0007](adr/0007-deployable-not-hosted.md) |
| 2026-11-14 | Landlord verification by artisan command; no admin web role | [ACCESS.md](ACCESS.md#actors) |
| 2026-11-14 | Design system: ube primary (also prices), mango highlight, Plus Jakarta Sans headings; hue chosen to differ from Hilom/PesoTrack teal and the portfolio's orange, light mode only in v1 | [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md) |
| 2026-11-17 | Slice 6.1: landlord decisions take only the reservation row lock (no landlord move makes a free space blocked, so the room lock isn't needed); a decline rejects the pending proof with the decline reason; an undo clears `decided_by`/`decided_at`; every time guard lives in `Reservation::assertCanMoveTo()` | [RESERVATIONS.md](RESERVATIONS.md#transition-table) |
| 2026-11-17 | Slice 5.3: payment proofs are streamed through PHP after three checks (signature and expiry, the logged-in user named in the URL, the policy again), never served by S3 pre-signed URLs; the bucket stays unreachable from outside. Proofs are kept byte for byte (no re-encoding) and judged by content; the download carries `Content-Security-Policy: sandbox` | [ACCESS.md](ACCESS.md#private-files) |
| 2026-11-17 | No map in v1: the listing shows the address and an "Open in Maps" link that searches the address text. No map script, tiles or coordinates | [ARCHITECTURE.md](ARCHITECTURE.md#stack) |
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

Ideas that came up and are deliberately not in any slice.

| Idea | Source |
| --- | --- |
| Admin web UI for landlord verification | Kickoff |
| Undo "moved in" | RESERVATIONS correction paths |
| No-shows: a `confirmed` reservation whose stay passed without "moved in" is left as it is; decide what the job should do with it | 6.3 |
| Per-member email preferences (who gets which mail) | 6.3 |
| SMS or push notifications, digest emails, calendar invites for viewings | 6.3 |
| An audit row for the system's own moves (expired holds, ended stays) | 6.3 |
| Undo a decline within a short window, if the space is still free (today a mistaken decline is final; the renter reserves again) | Slice 6.1 |
| Common reasons to pick from when rejecting a proof ("The amount is wrong", "I can't read it"), next to the free text | Slice 6.1 |
| A reservation's history on its page (who confirmed, rejected, undid, and when), from the audit log; today the page shows only the latest decision | Slice 6.1 |
| Show the renter which member decided (today the renter sees the landlord account's name only) | Slice 6.1 |
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
| A third, mid-size photo variant (about 960 px WebP) next to `-thumb` and `-full`: a phone's listing cover picks the 1600 px file (153 kB) because the 480 px thumb is too small, and Lighthouse's image-delivery audit puts the saving at about 120 kB (listing page LCP 3.5 s on simulated slow 4G, Performance 90). Needs a new file per photo, a backfill for existing rows, and changes in `PhotoProcessor`, the actions that write and delete photo files, the seeder and their tests | Slice 7.2 |
| Run Lighthouse once against a deployment with TLS: CI serves plain HTTP on localhost, so `is-on-https` and `redirects-http` are skipped there (and HTTP/2 isn't measured) | Slice 7.2 |
| Cover the invitation page's usable states (register, log in, join) in the accessibility sweep: it needs a live invitation link, which only the phase 4 demo can make today, so the sweep covers the neutral 404 | Slice 7.2 |
| Real screen-reader passes (NVDA, VoiceOver, TalkBack) on the reserve and decision flows: 7.2 could only check the accessibility tree and the keyboard order in the dev environment ([perf/accessibility.md](perf/accessibility.md)) | Slice 7.2 |
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
| Show a caretaker's email on the members page, if owners ask for it (today names only) | Slice 3.2 |
| A map on the listing page. `lat`/`lng` were dropped (review after 4.2): if a map comes back, place the pin by geocoding the address or with a "drop a pin" picker, never typed coordinates | Slice 4.1, UI review |
| `DemoSeeder`'s street addresses already contain the barangay and city ("444 Kalayaan Street, Barangay Sampaloc, Manila"), so the full address on a listing repeats them. Fixing it changes the seeder's hash and the demo text the e2e tests read | Slice 5.1 |
| Free beds update as the move-in date changes (a partial reload), instead of the "Check free beds" button; needs JavaScript, so it would only enhance the plain form | Slice 5.1 |
| Let an owner fix an ugly generated listing link (`-2`) before publishing; the form field was removed as too technical | UI review |
| A styled list for date fields (the dropdown list is styled in Chromium via `appearance: base-select`; the date picker is still the browser's) | UI review |
| Redirect old slugs: let a published property's slug change and keep the old URL working with a 301 | Slice 4.1 |
| Delete a room or space that was never reserved (today only archive) | Slice 4.1 |
| Reorder or bulk-rename beds; a bed count change that adds several at once | Slice 4.1 |
| Show on the property page which spaces are blocked from archiving, and by whom, before the owner clicks | Slice 4.1 |
| The Phase 5 search e2e (`search.spec.ts`, Quezon City from today + 14 days) fails on a freshly seeded database (also on `main`, checked in 7.1): the demo has no free place there at that date, so it depends on when the volume was seeded. Pick the place and date from the data, or have the seed leave a known free window | Slice 7.1 |
| HEIC/HEIF uploads (iPhones' default format; today the owner has to export a JPEG) | Slice 4.2 |
| Sweep temporary uploads (`uploads/tmp/` on the proofs disk) older than a day: a worker killed at the wrong moment can leave one behind | Slice 4.2 |
| Retry a failed photo without choosing the file again, when the failure was storage and not the file (today the upload is deleted on failure) | Slice 4.2 |
| Upload progress per file, and drag-and-drop to reorder next to the Up and Down buttons | Slice 4.2 |
| Photos per room ("Bed area of Room 2"), not only per property | Slice 4.2 |
| Tell an owner when an import leaves their property without a linked barangay (today they see it only as a publish checklist item, or on the form) | Slice 4.4 |
| Invite a co-owner (today only caretakers can be invited; the `role` column already allows `owner`) | Slice 4.3 |
| Change a member's role (caretaker to owner and back) without removing and inviting again | Slice 4.3 |
| Let a member leave an account themself (today another owner removes them) | Slice 4.3 |
| After "Log out" on the invitation page (logged in with another email), come back to the invitation instead of the home page | Slice 4.3 |
| Tell the owner by email when an invitation is accepted | Slice 4.3 |
| Show on the members page who was removed and when (the audit log has it; there is no page for it yet) | Slice 4.3 |
| A preview of the listing before publishing | Slice 4.3 |
| Tell the owner why a photo failed (unreadable file or storage) instead of one message | Slice 4.2 |
| Full-text search (property names, house rules) and saved searches | Slice 5.2 |
| Search results on a map | Slice 5.2 |
| Barangay in the search link by PSGC code instead of id, so a link survives a reimport into an empty database | Slice 5.2 |
| Home-page "Where" lists only cities with listings; a province-aware combobox would reach every place | Slice 5.2 |
| Let a renter replace a proof while it waits for review (today they cancel, or wait for the landlord to reject it) | Slice 5.3 |
| Upload progress on the proof form, and a client-side check of the 5 MB limit before sending | Slice 5.3 |
| Check how desktop browsers open a PDF proof served with `Content-Security-Policy: sandbox`: Chromium is expected to refuse its built-in PDF viewer in a sandboxed document (plugins are disabled there) and offer a download instead (not verified by hand; headless Chromium always downloads PDFs). If landlords want PDFs inline, render a first-page preview server-side | Slice 5.3 |
| Delete proof files when a reservation's data is purged (a retention policy for personal data: proofs are kept forever today) | Slice 5.3 |
| E2E on a 6-worker local run against the dev stack times out (7 GB machine, Vite dev server): run `npx playwright test --workers=2` locally, as CI's 4-vCPU runner does | Slice 5.3 |
| A first-time sign-up through Reserve comes back to the room only after confirming the email (the link is a full page load); a "Continue to your room" link on the "confirm your email" page would help someone who confirms on another device | Slice 5.3 |
