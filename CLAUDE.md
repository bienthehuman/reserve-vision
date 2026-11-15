# ReserveVision: notes for agents and contributors

Read [docs/CONVENTIONS.md](docs/CONVENTIONS.md) first. The docs in `docs/` are the source of truth. Update them in the same PR as any behaviour change.

## Workflow

- One slice = one branch (`feat/<phase>.<slice>-name`, e.g. `feat/1.1-app-skeleton`) = one PR. The user reviews, merges and says "merged and pulled".
- Make in-slice design calls yourself and list them under **Decisions to check** in the PR; ask only when a choice is costly to reverse.
- **No AI attribution** in commits or PR descriptions (no Co-Authored-By, no "Generated with" footer).
- Out-of-slice ideas go to the backlog table in docs/BUILD_PLAN.md.
- Never use the word "tenant" (ADR 0005).

## Environment

Dev runs entirely in Docker Compose ([compose.yaml](compose.yaml)). The host needs only Docker with Compose v2, plus Node 24 for `npm run e2e`. Host PHP (8.3) is **not** used: run PHP, Composer and Artisan inside the `app` container.

### Setup

`bin/setup` (or `make setup`): creates `.env` from `.env.example` if missing, builds the image, `composer install`, `npm ci`, generates `APP_KEY` if empty, starts `db`, migrates as `migrator` and seeds as `app` (before the worker, which needs the `cache` and `jobs` tables), `docker compose up -d --wait`, then waits until `/` is server-rendered. Non-interactive and idempotent.

Inside the dev container (`RV_DEVCONTAINER=1`, see [Dev container](#dev-container-and-codespaces)) the same script runs the steps directly in the workspace container instead of through `docker compose`, and runs `npm run build` instead of relying on the Vite dev server.

### Services and ports

Host ports bind to `127.0.0.1` and are set in `.env` (chosen to avoid Hilom's 5432/5433, 1025, 8025 and the host's 80/3306).

| Service | Image | Inside | Host | Notes |
| --- | --- | --- | --- | --- |
| `app` | `reservevision-app:dev` ([docker/dev/app.Dockerfile](docker/dev/app.Dockerfile)), FrankenPHP 1.13 + PHP 8.4 | 8000 | `APP_PORT=8180` | Source bind-mounted at `/app`, runs as UID/GID 1000 |
| `ssr` | `node:24.22-bookworm-slim` | 5183 | `VITE_PORT=5183` | `npm run dev`: Vite dev server, HMR **and** Inertia SSR (`/__inertia_ssr`) |
| `worker` | app image | — | — | `php artisan queue:work` (database queue) |
| `scheduler` | app image | — | — | `php artisan schedule:work` |
| `db` | `mysql:8.4` (8.4.13) | 3306 | `FORWARD_DB_PORT=33306` | Users created on first start by `docker/mysql/initdb.d/10-create-users.sh` |
| `mail` | `axllent/mailpit:v1.33` | 8025 / 1025 | `FORWARD_MAILPIT_DASHBOARD_PORT=8126` / `FORWARD_MAILPIT_PORT=1126` | Inbox: http://localhost:8126 |

URLs: app http://localhost:8180, styleguide http://localhost:8180/styleguide, health http://localhost:8180/up.

### Database users (ADR 0008)

| User | Grants | Connection | Used by |
| --- | --- | --- | --- |
| `migrator` | `ALL` on the schema | `mysql_migrator` (`DB_MIGRATOR_USERNAME` / `DB_MIGRATOR_PASSWORD`) | `composer migrate` / `make migrate` = `php artisan migrate --database=mysql_migrator --force`. Never anything else |
| `app` | `SELECT, INSERT, UPDATE, DELETE` on the schema, no DDL | `mysql` (default; `DB_USERNAME` / `DB_PASSWORD`) | web, worker, scheduler, seeders, **tests** |

- Schemas: `reservevision` (dev) and `reservevision_test` (Pest). Both get both users.
- [database/sql/create-users.sql](database/sql/create-users.sql) is plain SQL with no Docker assumptions. CI and production run it with the `mysql` client after `SET @db_name = …, @app_password = …, @migrator_password = …;`. Idempotent.
- The init script only runs on an **empty** `db-data` volume. After changing it, run `docker compose down -v && bin/setup`.
- Plain `php artisan migrate` (without `--database=mysql_migrator`) fails with error 1142 on purpose.
- **`audit_logs` is append-only by triggers, not grants** (slice 2.1, [ADR 0008](docs/adr/0008-least-privilege-database-users.md)): `audit_logs_no_update` and `audit_logs_no_delete` reject `UPDATE` and `DELETE` from `app` with error 1644. `app` can't drop them (no `TRIGGER`) or `TRUNCATE` (no `DROP`). The database server needs `log_bin_trust_function_creators=1` for the migrator to create them: `compose.yaml` and `compose.prod.yaml` pass it, and `_tests.yml` runs `SET GLOBAL` as root. An existing dev `db` container started before 2.1 needs `docker compose up -d db` (recreates it with the flag), or the migration fails with a message that says so.

### Demo data (slice 2.2)

`bin/setup` runs `php artisan db:seed` on every invocation: the amenity catalogue (always) and `DemoSeeder` (only if `owner@example.com` doesn't exist yet, so reruns change nothing). To reseed from scratch: `docker compose down -v && bin/setup` (the seeder itself takes about 1 s; a whole cold `bin/setup` about a minute). `DemoSeeder` throws in production unless `APP_DEMO=true` (`config('app.demo')`, documented in both env examples); `DatabaseSeeder` skips it there instead. Logins and the "last bed" room are in the [README](README.md#demo-logins); data files are in `database/data/` (`places.php`, `names.php`, `amenities.php`). Ids are assigned by the seeder (max + 1) so rows don't depend on auto-increment counters, and "now" is read once, so a fixed seed gives identical rows on any day apart from the relative dates. Reservations come from `Database\Seeders\Demo\StayScheduler` (back-to-back stays per space), not `ReserveSpace`.

`php artisan landlord:verify {slug}` verifies an account, `--revoke` clears it and unpublishes the account's properties in one transaction, `--list` shows the unverified accounts.

The seeder self-checks are in `tests/Feature/Seeding/DemoSeederTest.php`; each test seeds inside its transaction with "now" frozen at 2026-11-15 10:00 Manila. The last test compares a hash of the business columns: change the seeder on purpose, copy the printed hash into the test and say why in the PR.

### Running the checks

| Suite | Command | Notes |
| --- | --- | --- |
| Pint | `docker compose exec app composer lint` (`make lint`) | `composer format` fixes. Laravel preset + `declare_strict_types` |
| Larastan | `docker compose exec app composer analyse` (`make analyse`) | Level max, no baseline. Covers `app`, `bootstrap/app.php`, `config`, `database`, `routes` |
| Pest | `docker compose exec app composer test` (`make test`); in parallel like CI: `docker compose exec app composer test -- --parallel --processes=4` | Runs as `app` on `reservevision_test`; in parallel, worker N uses `reservevision_test_N` (1–4, created by the init script). `tests/TestCase.php` runs `migrate:fresh` once per process as `migrator` (refuses any schema not ending in `_test` or `_test_N`), then each Feature test runs in a `DatabaseTransactions` transaction. **Never use `RefreshDatabase`.** Feature tests that render pages need SSR: in dev that's the running `ssr` service; `INERTIA_SSR_THROW_ON_ERROR=true` makes a missing SSR server fail loudly |
| Vitest | `docker compose exec ssr npm run test` (`make vitest`) | jsdom; `resources/js/**/*.test.{ts,tsx}` |
| TypeScript | `docker compose exec ssr npm run types` (`make types`) | TS 7 (`tsc --noEmit`) |
| Playwright + axe | `npm run e2e` on the host (`make e2e`) | Needs the stack running and `npx playwright install chromium` once. `E2E_BASE_URL` (default `http://localhost:8180`) points it at another stack; `E2E_SKIP_DEV_PAGES=1` skips `/styleguide` against production |
| Production build | `docker compose exec ssr npm run build` | Client to `public/build`, SSR bundle to `bootstrap/ssr/app.js`. Delete `public/hot` or stop `ssr` to test the built assets |

### CI (GitHub Actions)

Every PR and every push to `main` runs five workflows ([.github/workflows](.github/workflows)). Each cancels a superseded run on the same branch, runs with `contents: read`, and has a job timeout.

| Workflow | File | Check name(s) | What it runs |
| --- | --- | --- | --- |
| Tests (MySQL 8.4) | `tests-mysql.yml` | `MySQL 8.4 / Pest` | Reusable [_tests.yml](.github/workflows/_tests.yml) with `mysql:8.4` |
| Tests (MariaDB 11.4) | `tests-mariadb.yml` | `MariaDB 11.4 / Pest` | Same, with `mariadb:11.4` |
| Quality | `quality.yml` | `Pint + Larastan`, `TypeScript + Vitest + build` | `composer lint`, `composer analyse`, `npm run types`, `npm run test`, `npm run build`, then the CSS budget ([bin/check-css-budget.mjs](bin/check-css-budget.mjs): `app.css` ≤ 35 kB gzipped, size in the job summary) |
| E2E | `e2e.yml` | `Playwright + axe` | `bin/setup` (the dev Compose stack, MySQL), then `npm run e2e`. On failure: the Playwright report and traces as the `playwright-report` artifact, and the stack logs |
| Production | `production.yml` | `Production stack` | The ADR 0007 guard. ShellCheck, `bin/ci-production-env` (throwaway secrets, Caddy on plain HTTP `:80` published at 8280), `./deploy.sh --current` (build, migrate as `migrator`, up, optimize, `app:doctor`, `/up`), image size in the job summary, `bin/smoke-production` (SSR'd pitch text, `/up` 200, `/styleguide` 404, security headers incl. HSTS), then `npm run e2e` against it with `E2E_SKIP_DEV_PAGES=1`. On failure: `playwright-report-production` artifact and the stack logs |

How `_tests.yml` differs from dev: PHP comes from `setup-php` on the runner (no app container) and the database is a service container. Root runs [database/sql/create-users.sql](database/sql/create-users.sql) with the `mysql` client for `reservevision_test` and `reservevision_test_1`…`_4`, `composer migrate` runs as `migrator`, `npm run build` plus `php artisan inertia:start-ssr` replace the Vite dev server for SSR, and Pest runs as `app` with `--parallel --processes=4`.

Dependabot ([.github/dependabot.yml](.github/dependabot.yml)) opens weekly PRs for Composer, npm, Actions, Dockerfiles, Compose images (including the pinned MinIO digest) and dev container features, with minor and patch updates grouped into one PR per ecosystem.

**Reproducing a CI failure locally** (stack running):

| Failing check | Run |
| --- | --- |
| `… / Pest` | `docker compose exec app composer test -- --parallel --processes=4` (serially without the flags, to rule out a worker clash). For MariaDB-only failures, start `mariadb:11.4`, run the SQL file as root for the schemas above, and point `DB_HOST` at it |
| `Pint + Larastan` | `make lint` (`composer format` fixes) and `make analyse` |
| `TypeScript + Vitest + build` | `make types`, `make vitest`, `docker compose exec ssr npm run build && docker compose exec ssr node bin/check-css-budget.mjs`, then `rm -rf public/build bootstrap/ssr` |
| `Playwright + axe` | `make e2e`, or download the `playwright-report` artifact and open a trace with `npx playwright show-trace test-results/<test>/trace.zip` |
| `Production stack` | The same steps locally (port 8280 must be free): `bin/ci-production-env 8280 && ./deploy.sh --current && bin/smoke-production http://localhost:8280`, then `E2E_BASE_URL=http://localhost:8280 E2E_SKIP_DEV_PAGES=1 npm run e2e`. Tear down with `docker compose -f compose.prod.yaml --env-file .env.production down -v && rm .env.production` |

### Pinned versions

PHP 8.4.27 (FrankenPHP 1.13.5 image), Composer 2.9, Laravel 13.42, inertia-laravel 3.7, league/flysystem-aws-s3-v3 3.32 (AWS SDK trimmed to S3 by `extra.aws/aws-sdk-php`), Pest 5.3 (ParaTest held below 7.28 by a `conflict` in composer.json, see quirks), Larastan 3.15, Pint 1.32. Node 24.22, Vite 8.3, React 19.3, @inertiajs/react + @inertiajs/vite 3.11, TypeScript 7.0, Bootstrap 5.3.9, React-Bootstrap 2.10, react-bootstrap-icons 1.11, Sass 1.108, Vitest 5.1, Playwright 1.65.1 (exact, matches its browser build), @axe-core/playwright 4.14. MySQL 8.4.13, Mailpit 1.33. Production only: Caddy 2.11 (`caddy:2.11-alpine`), MinIO RELEASE.2026-11-12 (`cgr.dev/chainguard/minio:latest-dev`, pinned by digest). Dev container: Node feature 1.7.1 (`devcontainer-lock.json`).

### Production stack (slice 1.3)

Full guide: [DEPLOY.md](DEPLOY.md). Pieces:

| File | What |
| --- | --- |
| [Dockerfile](Dockerfile) | Multi-stage: `php-base` (FrankenPHP 1.13 + extensions, same as dev) → `vendor` (`--no-dev`, classmap-authoritative, explicit source allowlist) → `assets` (Node: `npm run build`, source maps deleted) → `runtime` (code owned by root, only `storage/` and `bootstrap/cache` writable, runs as `app` UID 1000, OPcache with `validate_timestamps=0` from [docker/prod/php.ini](docker/prod/php.ini), plus only the `node` binary for SSR). One image for `app`, `worker`, `scheduler` and `ssr` |
| [.dockerignore](.dockerignore) | Production context: no `.env*`, tests, `prompts/`, `node_modules`, docs. The dev image has its own [docker/dev/app.Dockerfile.dockerignore](docker/dev/app.Dockerfile.dockerignore) |
| [compose.prod.yaml](compose.prod.yaml) | Project `reservevision-prod`. `caddy` ([docker/caddy/Caddyfile](docker/caddy/Caddyfile): HTTPS for `DOMAIN`, `/media/photos/*` → photos bucket, GET/HEAD only), `app`, `ssr` (`node bootstrap/ssr/app.js`), `worker` (`--max-time=3600`), `scheduler`, `db` (same init script and SQL as dev, without the test schemas), `minio`, `minio-setup` ([docker/minio/setup.sh](docker/minio/setup.sh)). Only Caddy publishes ports (`HTTP_PORT`/`HTTPS_PORT`) |
| `.env.production` | Git-ignored. Every variable documented in [.env.production.example](.env.production.example). Used both for `${…}` in the Compose file (`--env-file`) and as the PHP containers' environment (`env_file`); there is no `.env` in the image |
| [deploy.sh](deploy.sh) | `./deploy.sh <tag>` or `--current`. Preflight → checkout → build → db + MinIO → migrate as `migrator` → `up -d --wait` → `optimize` in app/worker/scheduler + `queue:restart` → `app:doctor` → GET `/up`. `set -Eeuo pipefail`, body in `main()` (the checkout replaces the file), prints the rollback command on failure |
| [bin/ci-production-env](bin/ci-production-env) | Writes a throwaway `.env.production` (random secrets, `DOMAIN=:80`). Refuses to overwrite one it didn't write |
| [bin/smoke-production](bin/smoke-production) | curl-only smoke test of a running stack. TODOs for slices 3.1 (listing page) and 5.1 (login) |

Media disks ([config/filesystems.php](config/filesystems.php)): `photos` (public) and `proofs` (private). `MEDIA_STORAGE=local` (default; dev and tests, under `storage/app`) or `s3` (production: MinIO, or any S3). Not used by domain code until slice 4.2.

Proxies: `bootstrap/app.php` trusts `X-Forwarded-*` from any address (`trustProxies(at: '*')`), because the app port is only reachable from Caddy (production) or GitHub's port forwarding (Codespaces).

### `php artisan app:doctor`

[app/Console/Commands/DoctorCommand.php](app/Console/Commands/DoctorCommand.php). Prints a pass/fail table and exits 1 if anything fails. Checks: connected as `DB_USERNAME`; `CREATE TABLE` denied (1142); `UPDATE` on `audit_logs` rejected by the trigger (probe row in a rolled-back transaction); queue table readable (in production the queue must be `database`); write/read/delete a temp file on `photos` and `proofs`; mail config complete (in production `log`/`array` mailers fail). Run by `deploy.sh` and so by the production workflow; works in dev too (`docker compose exec app php artisan app:doctor`).

### Dev container and Codespaces

[.devcontainer/devcontainer.json](.devcontainer/devcontainer.json) on top of `compose.yaml` plus [.devcontainer/compose.devcontainer.yaml](.devcontainer/compose.devcontainer.yaml) (project `reservevision-devcontainer`, so it never clashes with a local dev stack). The workspace container is `app` (user `app`, `/app`), with Node 24 from the dev container feature. `postCreateCommand` is `bin/setup`. Forwards 8000 (app, opens in the browser) and `mail:8025` (Mailpit).

Differences from the plain dev stack: **built assets, no Vite dev server** (a Vite origin on a second forwarded port would need a public port and CORS), so run `npm run build` after frontend changes; the `ssr` service runs the production SSR bundle with `node --watch-path` and reloads on rebuilds. No host ports. `worker` and `scheduler` restart a few times until `bin/setup` has migrated.

Verify locally from a clean clone (not this working tree: the build removes `public/hot`):

```bash
git clone --branch <branch> . /tmp/rv-devc && cd /tmp/rv-devc
npx @devcontainers/cli up --workspace-folder .
npx @devcontainers/cli exec --workspace-folder . curl -s http://localhost:8000/ | grep -o '<h1[^>]*>[^<]*</h1>'
docker compose -p reservevision-devcontainer down -v
```

Measured: about 0.8 GB at rest and 1.7 GB peak (during `npm run build`), so `hostRequirements` is 2 cores and 8 GB.

### Schema (slice 2.1)

[docs/DATA_MODEL.md](docs/DATA_MODEL.md) is the spec, with an ERD. Migrations are `database/migrations/2026_11_15_*`; CHECKs go through `App\Database\Constraints` (named, `<table>_<column>_valid` for enums). People's names are `first_name` + optional `middle_name` + `last_name` + optional `suffix` ([CONVENTIONS](docs/CONVENTIONS.md#names)); use `$user->full_name`. Enums are in `app/Enums` (values equal the CHECKs: `tests/Feature/Schema/EnumChecksTest.php`), models in `app/Models` (typed casts, `#[Fillable]`, no scopes or policies yet), factories in `database/factories` (`Property::factory()->published()`, `Room::factory()->shared(beds: 4)`, `Reservation::factory()->expiredHold()`, `->proofUploaded()`, `->confirmed()`…, always deriving `landlord_account_id` from the parent). `ReservationStatus::transitions()` is compared with the table in `docs/RESERVATIONS.md` by `tests/Feature/Docs/TransitionTableTest.php`; `tests/Arch/ReservationWritesTest.php` forbids writing a reservation's status outside `app/Actions`.

Tests assert driver error codes, not messages (`dbErrorCode()` in `tests/Pest.php`): 1452 foreign key, 1062 duplicate, 1264 out of range, 3819 (MySQL) or 4025 (MariaDB) CHECK, 1644 trigger, 1142 denied.

### Reservations (slice 2.3)

[docs/RESERVATIONS.md](docs/RESERVATIONS.md) is the spec. `App\Actions\ReserveSpace::handle($renter, $room, $moveIn, $months)` creates a 24-hour hold: cheap checks first, then one transaction (3 attempts on deadlock) that locks the renter row, then the room row, and only then reads, **every read a locking read** (the snapshot pitfall: comment in `ReserveSpace::reserve()`, [ADR 0001](docs/adr/0001-room-lock-vs-exclusion-constraint.md#the-read-snapshot-added-in-slice-23)). `tests/Feature/Reservations/LockingTest.php` fails if a non-locking `SELECT` or a subquery sneaks in.

- `Reservation::scopeBlocking()`, `scopeOverlapping($in, $out)` and `scopeOpenForRenter($renter)` are the only definitions of those words; they qualify their columns, so they work on joins. "Now" is a PHP binding from Laravel's clock.
- `Reservation::assertCanMoveTo($to)` = transition table + hold-expiry guards. Status-changing actions (5.3, 6.1, 6.3…) call it on the row they've just locked.
- `App\Support\BusinessDate::today()`: the Manila calendar date as midnight **UTC**, the same shape as the `immutable_date` casts, so it compares with `move_in` directly. `BusinessDate::of($date)` drops the time and timezone of any date.
- Domain exceptions: `app/Exceptions/Domain/`, extending the abstract `BusinessRuleException` (the only non-final class, exempted in `tests/Arch/ArchTest.php`). JSON → 422 `{message}`; Inertia (`X-Inertia`) → 303 back with the `error` flash, shared as `flash.error`; otherwise the 422 page. Not logged.
- Tests in `tests/Feature/Reservations/` freeze "now" at 2027-03-15 10:00 Manila, so the window is Mar 15 – Jun 13, 2027 and T3 can use Jun 1. Helpers `reservableRoom()`, `reserve()` and `onlySpace()` are in `tests/Pest.php`.

### Quirks hit in 2.3

- **A locking clause doesn't reach subqueries**: in `SELECT … WHERE NOT EXISTS (SELECT …) FOR UPDATE` the subquery is a plain snapshot read. Keep each check its own locking query.
- **`SET TRANSACTION ISOLATION LEVEL` inside an open transaction** fails on MySQL (1568), and every Pest test is inside one (`DatabaseTransactions`). That's why `ReserveSpace` uses locking reads, not `READ COMMITTED`.
- **Laravel's `exists()` wraps the query in `select exists(…)`**, which would put the lock clause in a subquery; use `->sharedLock()->count()` instead.
- **Pest resolves closures in datasets** before calling the test, so a dataset entry `fn () => Room::factory()->create()` arrives as a `Room`, not a closure.

### Quirks hit in 2.1

- **State closures see unresolved attributes** in Laravel factories (nested factories and closures are not yet evaluated). Derive from a parent in a *definition* closure (`fn (array $attributes)`, after the parent key), and do lookups that need real ids in `afterCreating`.
- **`information_schema.TRIGGERS` and `SHOW TRIGGERS` need the `TRIGGER` privilege**, which `app` lacks, so the suite checks the triggers by behaviour, not by listing them.
- **MySQL stores CHECK clauses with escaped literals** (`_utf8mb4\'renter\'`), MariaDB with plain quotes: the enum test's regex accepts both.
- **A CHECK can't use a column whose foreign key has a referential action** (`ON DELETE CASCADE`…). No foreign key here has one; keep it that way.
- **MySQL's CHECK violation is `HY000`/3819, MariaDB's `23000`/4025**: compare driver codes, and use `CHECK_VIOLATION` from `tests/Pest.php`.
- **`migrate:fresh` repeats** because the triggers die with the table.

### Quirks hit in 1.1

- **The FrankenPHP image has its own `HEALTHCHECK`** (probes the Caddy admin port), which `worker` and `scheduler` inherit, so `up --wait` reports them unhealthy. They override it with `kill -0 1` (the `artisan` process is alive). Not `disable: true`: the Compose on GitHub's runners then fails `up --wait` with "container … has no healthcheck configured" (fixed in 1.2).
- **Vite host check**: the app calls the SSR endpoint as `http://ssr:5183`, so `server.allowedHosts: ['ssr']` is needed (otherwise a 403 and a silent fallback to client rendering). `INERTIA_SSR_HOT_URL` tells Laravel to use that URL instead of the browser-facing one in `public/hot`.
- **Vite CORS**: setting `server.origin` replaces laravel-vite-plugin's CORS list, so `server.cors.origin` is set to `APP_URL` explicitly.
- **React-Bootstrap imports**: import from the package root (`import { Button } from 'react-bootstrap'`). Deep imports (`react-bootstrap/Button`) resolve to CommonJS under Node SSR and fail with "Element type is invalid … got: object".
- **CSP and axe**: axe-core injects a `<style>` that the CSP blocks. The e2e test checks console and CSP errors *before* running axe.
- **Sass**: Bootstrap 5.3 still needs `@import`; `quietDeps` + `silenceDeprecations: ['import']` in [vite.config.ts](vite.config.ts). Our partials use `sass:color` / `sass:map`, not the deprecated globals.
- **Cold Vite dev server**: the first load after `ssr` starts can fail with `504 Outdated Optimize Dep` while Vite discovers dependencies. `optimizeDeps.include` and `server.warmup` in [vite.config.ts](vite.config.ts) prevent this; add new heavy dependencies there.
- **SSR warm-up**: for a few seconds after `ssr` starts, Inertia logs "SSR skipped, module graph is still warming up" and pages fall back to client rendering. `bin/setup` waits until `/` is server-rendered; after a manual `docker compose restart ssr`, wait before running e2e or the Pest SSR test.
- **Playwright on the host** needs `npx playwright install chromium` after any `@playwright/test` version change.
- The MySQL healthcheck connects over TCP as `app`, so "healthy" also means the init script has finished.

### Quirks hit in 1.2

- **Pest 5.3.5 + ParaTest 7.28**: `--parallel` crashes with `getTestFiles(): Argument #1 ($filename) must be of type string, array given` (ParaTest 7.28 groups `SuiteLoader::$tests`). `composer.json` has `"conflict": {"brianium/paratest": ">=7.28"}`; drop it once a Pest release supports 7.28.
- **Parallel Pest and the `app` user**: Laravel's own per-process test databases need `CREATE DATABASE`. phpunit.xml sets `LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES=1` and `tests/TestCase.php` points each worker at `<DB_DATABASE>_<token>` instead. A dev `db-data` volume from before 1.2 lacks `reservevision_test_1`…`_4`: run `docker compose down -v && bin/setup` (or run the SQL file as root for those four schemas).
- **Vite restarts on config changes** (for example after `git pull` touches `vite.config.ts`) and can come back on its default port 5173 instead of 5183, so SSR tests fail with "Failed to connect to ssr port 5183". `docker compose restart ssr` fixes it.
- **E2E on the runner**: the dev containers run as UID 1000, the runner as 1001, so `e2e.yml` creates `.env` and makes the checkout world-writable before `bin/setup`.

### Quirks hit in 1.3

- **MinIO images**: `minio/minio` is gone from Docker Hub and Quay needs a login. `cgr.dev/chainguard/minio:latest-dev` is built from source and includes `sh`, `wget` and `mc`; Chainguard's free tier only has `latest*` tags, so it's pinned by digest and Dependabot bumps it.
- **`mc anonymous set download` also allows `ListBucket`**: anyone could list every photo key. [docker/minio/setup.sh](docker/minio/setup.sh) sets a GetObject-only policy instead.
- **Caddy directive order**: `rewrite` runs before `uri strip_prefix` in the same block, so the prefix was never stripped. Use `handle_path` (strips first).
- **The root `.dockerignore` applies to the dev image too**, and the Dev Containers CLI builds from a generated Dockerfile, so `docker/dev/app.Dockerfile.dockerignore` doesn't apply there. The root file must not exclude `docker/dev`.
- **Dev Containers Node feature** runs `source` in the remote user's login shell; the dev image's `app` user needs `--shell /bin/bash`.
- **SSR bundle externals**: `vite build --ssr` keeps dependencies external by default, so the bundle needs `node_modules` at runtime. `ssr.noExternal: true` for builds only (vite.config.ts) makes it self-contained; it reads `process.env.NODE_ENV` at runtime, so the `ssr` service sets `NODE_ENV=production`.
- **`set -e` and functions**: an `ERR` trap doesn't fire for failures inside functions without `set -E`.
- **Chainguard images run as UID 65532**: `docker compose run` of `minio-setup` for backups needs `--user 0:0` to write to a host directory.

