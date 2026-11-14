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

## Code

- PHP: `declare(strict_types=1)`, `final` classes by default, Pint (Laravel preset), Larastan at max level.
- An **action** is a `final` class with one public `handle()` method. It takes models and value objects, not requests. Anything that changes state runs in a transaction.
- **Status changes** go through `ReservationStatus::assertCanTransitionTo()`. There's no `$reservation->update(['status' => …])` outside actions; a Larastan rule or an architecture test (Pest `arch()`) enforces this.
- Money is an `int` of centavos in PHP, and a `number` in TypeScript formatted only at the view edge (`formatPeso()`).
- Domain exceptions carry a user-facing message, are rendered as `422` (or a flash on Inertia redirects), and are never shown as raw exceptions.
- TypeScript is `strict`. Page props are typed from shared `resources/js/types`.
- Naming: **landlord account**, **landlord member**, **renter**. Never "tenant" (an architecture test greps for it).

## Tests

| Kind | Tool | Where | Runs on |
| --- | --- | --- | --- |
| Unit and feature | Pest | `tests/Unit`, `tests/Feature` | MySQL + MariaDB matrix, as the `app` DB user |
| Architecture | Pest `arch()` | `tests/Arch` | same |
| Concurrency (real parallel HTTP) | Pest driving `curl_multi` against the running Compose stack | `tests/Concurrency` | MySQL + MariaDB matrix |
| Frontend unit | Vitest | `resources/js/**/*.test.ts` | Node |
| End-to-end + axe | Playwright | `e2e/` | MySQL only |

- Each acceptance test from the build plan is named with its number, for example `it('T1: 10 renters race for one single-space room')`, so the README's Guarantees section can link to it.
- Parallel Pest workers: at most 4 (`--processes=4`). The concurrency suite runs alone, without `--parallel`.

## Docs that are tested

- The permission matrix in [ACCESS.md](ACCESS.md#permission-matrix) is parsed by a test and compared with what the policies actually allow for each role.
- The transition table in [RESERVATIONS.md](RESERVATIONS.md#transition-table) is parsed by a test and compared with `ReservationStatus::transitions()`.

## ADRs

- `docs/adr/NNNN-kebab-title.md`, from [the template](adr/0000-template.md).
- Status: `Proposed` → `Accepted` (when the PR merges) → optionally `Superseded by NNNN`.
- Written so a reviewer who has never seen the project understands the trade-off in two minutes.
