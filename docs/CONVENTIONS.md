# Conventions

## Workflow

- **One slice = one branch = one PR.** Branch names follow Hilom:
  - `feat/<phase>.<slice>-short-name` for build-plan slices, for example `feat/1.1-app-skeleton`
  - `fix/…`, `test/…`, `chore/…` (tooling, quality passes) and `docs/…` for everything else
- Slices are numbered `<phase>.<slice>` in [BUILD_PLAN.md](BUILD_PLAN.md); the PR title starts with that number, for example `1.1 App skeleton and design tokens`.
- Doc files in `docs/` use `UPPER_SNAKE.md` (as in Hilom); ADRs use `NNNN-kebab-title.md`.
- Every PR description has: what changed, how it was tested, the slice's definition of done ticked off, and **Decisions to check**: design calls made inside the slice that the reviewer should confirm.
- Ideas that come up mid-slice go to the backlog table in [BUILD_PLAN.md](BUILD_PLAN.md#backlog), never into the slice.
- **Docs are the source of truth.** A PR that changes behaviour updates the doc in the same PR.
- Commits and PRs carry **no AI attribution**.
- Past clients and collaborators stay anonymous in docs and PRs.

## Checks before merging

A PR merges only when every one of these checks is green (the CI workflows are described in [CLAUDE.md](../CLAUDE.md#ci-github-actions)):

| Check | Workflow | Covers |
| --- | --- | --- |
| `MySQL 8.4 / Pest` | Tests (MySQL 8.4) | Pest (unit, feature, arch) as the `app` user, 4 workers |
| `MariaDB 11.4 / Pest` | Tests (MariaDB 11.4) | The same on MariaDB |
| `MySQL 8.4 / Concurrency` | Tests (MySQL 8.4) | T1, T2, T8b and archive-versus-reserve: real parallel HTTP against a 16-thread FrankenPHP server, 5–6 rounds each, every race proven to overlap inside the server |
| `MariaDB 11.4 / Concurrency` | Tests (MariaDB 11.4) | The same on MariaDB |
| `Pint + Larastan` | Quality | Code style, static analysis at max level |
| `TypeScript + Vitest + build` | Quality | `tsc --noEmit`, Vitest, production build (client + SSR), CSS budget |
| `Playwright + axe` | E2E | Smoke and accessibility on the dev stack, phone and desktop |
| `Production stack` | Production | Production image and `compose.prod.yaml` via `deploy.sh`, `app:doctor`, HTTP smoke test, Playwright + axe against it ([ADR 0007](adr/0007-deployable-not-hosted.md)) |

A failing check is fixed in the PR, not skipped or retried until it passes.

## Names

- A **person's** name is four fields everywhere (database, forms, seeders, factories, emails): `first_name`, an optional `middle_name`, `last_name` and an optional `suffix` (Jr., Sr., II, III, IV, V: the `NameSuffix` enum, chosen from a list, never typed). There is no single "name" field for a person. This keeps "Joshua Gonzales" and "Joshua Gonzales Jr." apart, which in the Philippines are often unrelated people.
- Show the full name with `$user->full_name` ("Joshua Michael Gonzales Jr.", skipping what's missing), and sort by last name, then first name. Never split or join names by hand.
- Names are stored trimmed with inner whitespace collapsed (the model does it). Compound first names ("Maria Cristina") and compound last names ("Dela Cruz") are fine in their own field.
- `suffix` is the one enum without a database CHECK: the PHP enum validates it (`Rule::enum(NameSuffix::class)`) and `resources/js/types/name.ts` lists it for the form's select, kept equal by a test. Adding a suffix is an enum case plus a line in that file, no migration.
- Things that aren't people keep one `name`: landlord accounts, properties, rooms, amenities.

## Code

- PHP: `declare(strict_types=1)`, `final` classes by default, Pint (Laravel preset), Larastan at max level.
- An **action** is a `final` class with one public `handle()` method. It takes models and value objects, not requests. Anything that changes state runs in a transaction.
- **Status changes** go through `Reservation::assertCanMoveTo()` (the transition table plus the hold-expiry guards), called on the row the action has just locked. There's no `$reservation->update(['status' => …])` outside actions; a Larastan rule or an architecture test (Pest `arch()`) enforces this.
- Money is an `int` of centavos in PHP, and a `number` in TypeScript formatted only at the view edge (`formatPeso()`).
- Domain exceptions live in `app/Exceptions/Domain/`, extend `BusinessRuleException`, carry a user-facing message, are rendered as `422` (or the `error` flash on Inertia redirects), aren't logged, and are never shown as raw exceptions.
- **Every landlord-side action records exactly one audit row per change it makes**, through `App\Support\AuditLogger::record($action, $subject, $before, $after)`, inside the action's transaction (a rolled-back action leaves no row). Action names are `subject.verb` in snake_case (`room.rate_changed`, `invitation.created`, `account.verification_revoked`); `actor_id` is the logged-in user, null for the operator and the system. Only changed fields are stored, and secrets (`password`, `remember_token`, `token`, `token_hash`) never are: a changed secret is stored as `[redacted]`. Each action's tests assert its rows.
- **Landlord-owned data** is reached through the isolation scope, the named public and renter scopes, and the policies ([ACCESS.md](ACCESS.md#how-it-works-slice-31)). Controllers authorize with the policy ability named after the matrix row. No `withoutGlobalScope` outside the named scopes (an architecture test enforces it).
- Business "today" comes from `BusinessDate::today()`, never `today()` or `now()->toDateString()` (those are UTC).
- TypeScript is `strict`. Page props are typed from shared `resources/js/types`.
- Naming: **landlord account**, **landlord member**, **renter**. Never "tenant" (an architecture test greps for it).

## Tests

| Kind | Tool | Where | Runs on |
| --- | --- | --- | --- |
| Unit and feature | Pest | `tests/Unit`, `tests/Feature` | MySQL + MariaDB matrix, as the `app` DB user |
| Architecture | Pest `arch()` | `tests/Arch` | same |
| Concurrency (real parallel HTTP) | Pest driving `curl_multi` against a multi-threaded FrankenPHP server that `bin/test-concurrency` starts on its own schema (`composer test:concurrency`) | `tests/Concurrency` | MySQL + MariaDB matrix |
| Frontend unit | Vitest | `resources/js/**/*.test.ts` | Node |
| End-to-end + axe | Playwright | `e2e/` | MySQL only |

- Each acceptance test from the build plan is named with its number, for example `it('T1: 10 renters race for one single-space room')`, so the README's Guarantees section can link to it.
- Parallel Pest workers: at most 4 (`--processes=4`). The concurrency suite runs alone, without `--parallel`.

## Docs that are tested

- The permission matrix in [ACCESS.md](ACCESS.md#permission-matrix) is parsed by a test (`tests/Feature/Docs/PermissionMatrixTest.php`) and compared with what the policies actually allow for each role, on the actor's own records and on another account's. A new policy ability, row or cell text needs a matching entry in the test's mapping.
- The list of isolated models in [ACCESS.md](ACCESS.md#isolation-three-layers) is compared with the models that use `BelongsToLandlordAccount` (`tests/Feature/Isolation/ScopeTest.php`).
- The transition table in [RESERVATIONS.md](RESERVATIONS.md#transition-table) is parsed by a test and compared with `ReservationStatus::transitions()`.

## ADRs

- `docs/adr/NNNN-kebab-title.md`, from [the template](adr/0000-template.md).
- Status: `Proposed` → `Accepted` (when the PR merges) → optionally `Superseded by NNNN`.
- Written so a reviewer who has never seen the project understands the trade-off in two minutes.
