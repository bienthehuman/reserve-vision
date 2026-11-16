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

`bin/setup` (or `make setup`): creates `.env` from `.env.example` if missing, builds the image, `composer install`, `npm ci`, generates `APP_KEY` if empty, starts `db`, migrates as `migrator`, imports the places and seeds as `app` (before the worker, which needs the `cache` and `jobs` tables; the places before the seeder, which uses real barangays), `docker compose up -d --wait`, then waits until `/` is server-rendered. Non-interactive and idempotent.

Inside the dev container (`RV_DEVCONTAINER=1`, see [Dev container](#dev-container-and-codespaces)) the same script runs the steps directly in the workspace container instead of through `docker compose`, and runs `npm run build` instead of relying on the Vite dev server.

### Services and ports

Host ports bind to `127.0.0.1` and are set in `.env` (chosen to avoid Hilom's 5432/5433, 1025, 8025 and the host's 80/3306).

| Service | Image | Inside | Host | Notes |
| --- | --- | --- | --- | --- |
| `app` | `reservevision-app:dev` ([docker/dev/app.Dockerfile](docker/dev/app.Dockerfile)), FrankenPHP 1.13 + PHP 8.4 | 8000 | `APP_PORT=8180` | Source bind-mounted at `/app`, runs as UID/GID 1000. 16 PHP threads (`FRANKENPHP_CONFIG: num_threads 16`, not FrankenPHP's 2 per CPU) |
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

- Schemas: `reservevision` (dev), `reservevision_test` (Pest) and `reservevision_concurrency` (the concurrency suite, slice 2.5). All get both users. `bin/setup` creates the concurrency schema on volumes from before 2.5.
- [database/sql/create-users.sql](database/sql/create-users.sql) is plain SQL with no Docker assumptions. CI and production run it with the `mysql` client after `SET @db_name = …, @app_password = …, @migrator_password = …;`. Idempotent.
- The init script only runs on an **empty** `db-data` volume. After changing it, run `docker compose down -v && bin/setup`.
- Plain `php artisan migrate` (without `--database=mysql_migrator`) fails with error 1142 on purpose.
- **`audit_logs` is append-only by triggers, not grants** (slice 2.1, [ADR 0008](docs/adr/0008-least-privilege-database-users.md)): `audit_logs_no_update` and `audit_logs_no_delete` reject `UPDATE` and `DELETE` from `app` with error 1644. `app` can't drop them (no `TRIGGER`) or `TRUNCATE` (no `DROP`). The database server needs `log_bin_trust_function_creators=1` for the migrator to create them: `compose.yaml` and `compose.prod.yaml` pass it, and `_tests.yml` runs `SET GLOBAL` as root. An existing dev `db` container started before 2.1 needs `docker compose up -d db` (recreates it with the flag), or the migration fails with a message that says so.

### Demo data (slice 2.2)

`bin/setup` runs `php artisan places:import` and then `php artisan db:seed` on every invocation: the amenity catalogue (always) and `DemoSeeder` (only if `owner@example.com` doesn't exist yet, so reruns change nothing). To reseed from scratch: `docker compose down -v && bin/setup` (the seeder itself takes about 1 s; a whole cold `bin/setup` about a minute). `DemoSeeder` throws in production unless `APP_DEMO=true` (`config('app.demo')`, documented in both env examples); `DatabaseSeeder` skips it there instead. Every published demo property also gets 3 to 6 ready photos (4.2): `Database\Seeders\Demo\DemoPhotoPainter` draws six scenes in four colourways with GD (no third-party images), each goes once through `PhotoProcessor`, and the files are written to the `photos` disk before the transaction commits (about 5 s more on a cold seed; a volume seeded before 4.2 has none until it is reseeded). Logins and the "last bed" room are in the [README](README.md#demo-logins); data files are in `database/data/` (`places.php`: the demo's seven city slugs and their price `rate`; `names.php`, `amenities.php`). Since 4.4 each property's barangay is drawn from its city's real barangays in the places tables (by PSGC code, one Faker draw as before), so the seeder throws if `places:import` hasn't run; the last-bed house is the Sampaloc district's first, "Barangay 395, Sampaloc". Ids are assigned by the seeder (max + 1) so rows don't depend on auto-increment counters, and "now" is read once, so a fixed seed gives identical rows on any day apart from the relative dates. Reservations come from `Database\Seeders\Demo\StayScheduler` (back-to-back stays per space), not `ReserveSpace`.

The "last bed" room is `/rooms/1` (pinned by `DemoSeederTest`), in the house at `/listings/manila/santos-bedspace-house` (`/rooms/1` redirects to it); [e2e/reserve.spec.ts](e2e/reserve.spec.ts) is the Phase 2 demo on it, through the listing page (phone viewport only, so the two Playwright projects don't race for the bed; on a dev stack it reuses renter1's existing hold on reruns).

`php artisan landlord:verify {slug}` verifies an account, `--revoke` clears it and unpublishes the account's properties in one transaction, `--list` shows the unverified accounts.

The seeder self-checks are in `tests/Feature/Seeding/DemoSeederTest.php`; each test imports the demo cities' rows of the real PSGC files (`importDemoPlaces()`, about 1,600 barangays, filtered once per process) and seeds inside its transaction with "now" frozen at 2026-11-15 10:00 Manila. The hash reads a property's city and barangay by PSGC code, not id (their auto-increment counters survive a rolled-back test). The last test compares a hash of the business columns: change the seeder on purpose, copy the printed hash into the test and say why in the PR.

### Running the checks

| Suite | Command | Notes |
| --- | --- | --- |
| Pint | `docker compose exec app composer lint` (`make lint`) | `composer format` fixes. Laravel preset + `declare_strict_types` |
| Larastan | `docker compose exec app composer analyse` (`make analyse`) | Level max, no baseline. Covers `app`, `bootstrap/app.php`, `config`, `database`, `routes` |
| Pest | `docker compose exec app composer test` (`make test`); in parallel like CI: `docker compose exec app composer test -- --parallel --processes=4` | Runs as `app` on `reservevision_test`; in parallel, worker N uses `reservevision_test_N` (1–4, created by the init script). `tests/TestCase.php` runs `migrate:fresh` once per process as `migrator` (refuses any schema not ending in `_test` or `_test_N`), then each Feature test runs in a `DatabaseTransactions` transaction. **Never use `RefreshDatabase`.** Feature tests that render pages need SSR: in dev that's the running `ssr` service; `INERTIA_SSR_THROW_ON_ERROR=true` makes a missing SSR server fail loudly |
| Concurrency | `docker compose exec app composer test:concurrency` (`-- --filter=T2` for one scenario) | T1, T2, T8b, (4.1) archive-versus-reserve and (4.3) the accept and remove races over real parallel HTTP; about 30 s. Starts its own server: see [Concurrency suite](#concurrency-suite-slice-25). Never with `--parallel` |
| Vitest | `docker compose exec ssr npm run test` (`make vitest`) | jsdom; `resources/js/**/*.test.{ts,tsx}` |
| TypeScript | `docker compose exec ssr npm run types` (`make types`) | TS 7 (`tsc --noEmit`) |
| Playwright + axe | `npm run e2e` on the host (`make e2e`) | Needs the stack running and `npx playwright install chromium` once. `E2E_BASE_URL` (default `http://localhost:8180`) points it at another stack; `E2E_SKIP_DEV_PAGES=1` skips `/styleguide` against production |
| Production build | `docker compose exec ssr npm run build` | Client to `public/build`, SSR bundle to `bootstrap/ssr/app.js`. Delete `public/hot` or stop `ssr` to test the built assets |

### CI (GitHub Actions)

Every PR and every push to `main` runs five workflows ([.github/workflows](.github/workflows)). Each cancels a superseded run on the same branch, runs with `contents: read`, and has a job timeout.

| Workflow | File | Check name(s) | What it runs |
| --- | --- | --- | --- |
| Tests (MySQL 8.4) | `tests-mysql.yml` | `MySQL 8.4 / Pest`, `MySQL 8.4 / Concurrency` | Reusable [_tests.yml](.github/workflows/_tests.yml) with `mysql:8.4`: Pest, and the concurrency suite in the dev app image |
| Tests (MariaDB 11.4) | `tests-mariadb.yml` | `MariaDB 11.4 / Pest`, `MariaDB 11.4 / Concurrency` | Same, with `mariadb:11.4` |
| Quality | `quality.yml` | `Pint + Larastan`, `TypeScript + Vitest + build` | `composer lint`, `composer analyse`, `npm run types`, `npm run test`, `npm run build`, then the CSS budget ([bin/check-css-budget.mjs](bin/check-css-budget.mjs): `app.css` ≤ 35 kB gzipped, size in the job summary) |
| E2E | `e2e.yml` | `Playwright + axe` | `bin/setup` (the dev Compose stack, MySQL), then `npm run e2e`. On failure: the Playwright report and traces as the `playwright-report` artifact, and the stack logs |
| Production | `production.yml` | `Production stack` | The ADR 0007 guard. ShellCheck, `bin/ci-production-env` (throwaway secrets, `APP_DEMO=true`, Caddy on plain HTTP `:80` published at 8280), `./deploy.sh --current` (build, migrate as `migrator`, `places:import`, up, optimize, `app:doctor`, `/up`), image size in the job summary, `php artisan db:seed --force` (demo data), an invitation link from `php artisan landlord:invite` exported as `E2E_INVITATION_URL` (masked; the phase 4 demo accepts through it), `bin/smoke-production` (SSR'd pitch text, `/up` 200, `/styleguide` 404, security headers incl. HSTS (`upgrade-insecure-requests` only against HTTPS), SSR'd login form, session cookie `Secure`/`HttpOnly`/`SameSite=Lax`, a CSRF login as `owner@example.com` landing on `/landlord`), then `npm run e2e` against it with `E2E_SKIP_DEV_PAGES=1`. On failure: `playwright-report-production` artifact and the stack logs |

How `_tests.yml` differs from dev: PHP comes from `setup-php` on the runner (no app container) and the database is a service container. Root runs [database/sql/create-users.sql](database/sql/create-users.sql) with the `mysql` client for `reservevision_test` and `reservevision_test_1`…`_4`, `composer migrate` runs as `migrator`, `npm run build` plus `php artisan inertia:start-ssr` replace the Vite dev server for SSR, and Pest runs as `app` with `--parallel --processes=4`.

Dependabot ([.github/dependabot.yml](.github/dependabot.yml)) opens weekly PRs for Composer, npm, Actions, Dockerfiles, Compose images (including the pinned MinIO digest) and dev container features, with minor and patch updates grouped into one PR per ecosystem.

**Reproducing a CI failure locally** (stack running):

| Failing check | Run |
| --- | --- |
| `… / Pest` | `docker compose exec app composer test -- --parallel --processes=4` (serially without the flags, to rule out a worker clash). For MariaDB-only failures, start `mariadb:11.4`, run the SQL file as root for the schemas above, and point `DB_HOST` at it |
| `… / Concurrency` | `docker compose exec app composer test:concurrency`. The job log has each race's timeline; on failure, `storage/logs/concurrency-server.log` and `laravel.log`. MariaDB-only: run the SQL file as root for `reservevision_concurrency` on a `mariadb:11.4`, point `DB_HOST` at it |
| `Pint + Larastan` | `make lint` (`composer format` fixes) and `make analyse` |
| `TypeScript + Vitest + build` | `make types`, `make vitest`, `docker compose exec ssr npm run build && docker compose exec ssr node bin/check-css-budget.mjs`, then `rm -rf public/build bootstrap/ssr` |
| `Playwright + axe` | `make e2e`, or download the `playwright-report` artifact and open a trace with `npx playwright show-trace test-results/<test>/trace.zip` |
| `Production stack` | The same steps locally (port 8280 must be free): `bin/ci-production-env 8280 && ./deploy.sh --current && bin/smoke-production http://localhost:8280`, then `E2E_BASE_URL=http://localhost:8280 E2E_SKIP_DEV_PAGES=1 npm run e2e`. Tear down with `docker compose -f compose.prod.yaml --env-file .env.production down -v && rm .env.production` |

### Pinned versions

PHP 8.4.27 (FrankenPHP 1.13.5 image) with the `gd` (JPEG, PNG, WebP) and `exif` extensions since 4.2 (both Dockerfiles and `setup-php` in CI), Composer 2.9, Laravel 13.42, inertia-laravel 3.7, league/flysystem-aws-s3-v3 3.32 (AWS SDK trimmed to S3 by `extra.aws/aws-sdk-php`), laravel/fortify 1.42 (pulls in laravel/passkeys and the 2FA libraries, unused), fakerphp/faker 1.24 (a runtime dependency since 2.4, for `DemoSeeder` in production), Pest 5.3 (ParaTest held below 7.28 by a `conflict` in composer.json, see quirks), Larastan 3.15, Pint 1.32. Node 24.22, Vite 8.3, React 19.3, @inertiajs/react + @inertiajs/vite 3.11, TypeScript 7.0, Bootstrap 5.3.9, React-Bootstrap 2.10, react-bootstrap-icons 1.11, Sass 1.108, Vitest 5.1, Playwright 1.65.1 (exact, matches its browser build), @axe-core/playwright 4.14, Downshift 9.4.0 (exact, since 4.4). MySQL 8.4.13, Mailpit 1.33. Production only: Caddy 2.11 (`caddy:2.11-alpine`), MinIO RELEASE.2026-11-12 (`cgr.dev/chainguard/minio:latest-dev`, pinned by digest). Dev container: Node feature 1.7.1 (`devcontainer-lock.json`).

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
| [bin/smoke-production](bin/smoke-production) | curl-only smoke test of a running stack, including a demo-owner login (needs demo data). TODO for slice 5.1 (public listing page) |

Media disks ([config/filesystems.php](config/filesystems.php)): `photos` (public) and `proofs` (private). `MEDIA_STORAGE=local` (default; dev and tests, under `storage/app`) or `s3` (production: MinIO, or any S3). Used by property photos since 4.2 ([Photos](#photos-slice-42)).

Proxies: `bootstrap/app.php` trusts `X-Forwarded-*` from any address (`trustProxies(at: '*')`), because the app port is only reachable from Caddy (production) or GitHub's port forwarding (Codespaces).

### `php artisan app:doctor`

[app/Console/Commands/DoctorCommand.php](app/Console/Commands/DoctorCommand.php). Prints a pass/fail table and exits 1 if anything fails. Checks: connected as `DB_USERNAME`; `CREATE TABLE` denied (1142); `UPDATE` on `audit_logs` rejected by the trigger (probe row in a rolled-back transaction); queue table readable (in production the queue must be `database`); write/read/delete a temp file on `photos` and `proofs`; image processing (GD decodes a JPEG and the photo resize code gives two WebP files, 4.2); mail config complete (in production `log`/`array` mailers fail); the places tables aren't empty (4.4). Run by `deploy.sh` and so by the production workflow; works in dev too (`docker compose exec app php artisan app:doctor`).

### Dev container and Codespaces

[.devcontainer/devcontainer.json](.devcontainer/devcontainer.json) on top of `compose.yaml` plus [.devcontainer/compose.devcontainer.yaml](.devcontainer/compose.devcontainer.yaml) (project `reservevision-devcontainer`, so it never clashes with a local dev stack). The workspace container is `app` (user `app`, `/app`), with Node 24 from the dev container feature. `postCreateCommand` is `bin/setup`. Forwards 8000 (app, opens in the browser) and `mail:8025` (Mailpit).

Differences from the plain dev stack: **built assets, no Vite dev server** (a Vite origin on a second forwarded port would need a public port and CORS), so run `npm run build` after frontend changes; the `ssr` service runs the production SSR bundle with `node --watch-path` and reloads on rebuilds. No host ports. `APP_DEMO=true`, so the login page shows the demo accounts. `worker` and `scheduler` restart a few times until `bin/setup` has migrated.

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

### Auth (slice 2.4)

[docs/ACCESS.md](docs/ACCESS.md#logging-in-slice-24) has the rules. Laravel Fortify, headless:

| Piece | Where |
| --- | --- |
| Config | [config/fortify.php](config/fortify.php): features `registration`, `resetPasswords`, `emailVerification` only; `lowercase_usernames`; `limiters.login` **null** on purpose (see quirks) |
| Wiring | [app/Providers/FortifyServiceProvider.php](app/Providers/FortifyServiceProvider.php): `Fortify::ignoreRoutes()`, Inertia views, `CreateNewUser`/`ResetUserPassword`, our `LoginResponse` / `RegisterResponse` / `VerifyEmailResponse` / `FailedPasswordResetLinkRequestResponse` (`app/Http/Responses`), `Password::defaults()` (8–255), the `registration` and `password-reset-link` rate limiters |
| Routes | [routes/auth.php](routes/auth.php) on Fortify's controllers: the complete list (no 2FA, passkey, profile or password-confirmation routes exist). Landlord pages: `Route::middleware(['auth', 'verified', 'landlord'])` in `routes/web.php` (`landlord` = `EnsureUserIsLandlord`, 403 for renters and for landlord users without a membership) |
| Actions | `App\Actions\RegisterRenter`, `App\Actions\RegisterLandlord` (user + unverified account + owner membership in one transaction, retried on a slug clash) take `App\Data\RegistrationDetails`; `App\Actions\Fortify\CreateNewUser` validates and normalizes the phone (`App\Support\PhilippineMobile` → `09XXXXXXXXX`) |
| Pages | `resources/js/Pages/Auth/{Login,Register,ForgotPassword,ResetPassword,VerifyEmail}.tsx`, `Pages/Landlord/Dashboard.tsx` (placeholder). Form pieces in `resources/js/Components/Form/` (`TextField`, `ErrorSummary`, `SubmitButton`, `useFocusOnError`). Shared props: `auth.user` (id, names, type, `email_verified`, never email or phone) and `flash.status` |
| Mail | `User::sendEmailVerificationNotification()` / `sendPasswordResetNotification()` → queued notifications in `app/Notifications/Auth` → Markdown mailables in `app/Mail/Auth` with views in `resources/views/mail/auth`, themed by [resources/views/mail/reservevision.css](resources/views/mail/reservevision.css) (`mail.markdown.theme`) |
| Messages | `lang/en/auth.php` and `lang/en/passwords.php`: only the lines we word differently |
| Demo panel | `App\Support\DemoLogins`, sent as the `demoLogins` prop **only** when `config('app.demo')` is true, so it is absent from the HTML otherwise (`tests/Feature/Auth/DemoLoginsTest.php`). `APP_DEMO=true` in the dev container and in CI's production run |

Tests: `tests/Feature/Auth/` (each feature, rate limits, intended URL, rollback of `RegisterLandlord`, demo panel), helpers `landlordUser()` and `registrationForm()` in `tests/Pest.php`. E2E: [e2e/auth.spec.ts](e2e/auth.spec.ts); shared helpers (console/CSP errors, axe) in [e2e/support.ts](e2e/support.ts).

**Reading Mailpit in tests**: the e2e sign-up test polls Mailpit's API, `GET {E2E_MAILPIT_URL}/api/v1/search?query=to:"<email>"` then `GET /api/v1/message/<ID>` (the `Text` part holds the link). `E2E_MAILPIT_URL` defaults to `http://localhost:8126`; the test is skipped with `E2E_SKIP_DEV_PAGES=1` (no Mailpit in production). Mails go through the queue, so the `worker` must be running. In Pest, use `Notification::fake()` and call `toMail()` on the notification to check the rendered mail.

### Concurrency suite (slice 2.5)

T1, T2 and T8b over **real parallel HTTP**: [tests/Concurrency/ReservationRaceTest.php](tests/Concurrency/ReservationRaceTest.php), its own PHPUnit config ([phpunit.concurrency.xml](phpunit.concurrency.xml)), so `composer test` never runs it. Results and negative controls: [ADR 0001](docs/adr/0001-room-lock-vs-exclusion-constraint.md#evidence-slice-25).

| Piece | What |
| --- | --- |
| [bin/test-concurrency](bin/test-concurrency) (`composer test:concurrency`) | Starts FrankenPHP on `127.0.0.1:8001` inside the app image, waits for `/up`, runs Pest with `phpunit.concurrency.xml` (extra arguments go to Pest), stops the server. Refuses a cached config. Log: `storage/logs/concurrency-server.log` |
| [docker/concurrency/Caddyfile](docker/concurrency/Caddyfile) | The server: `num_threads 16` (more than the largest race, 10; FrankenPHP's default of 2 per CPU is only 8 on a 4-vCPU CI runner), `DB_DATABASE=reservevision_concurrency`, `CONCURRENCY_PROBE=true`, SSR off, `BCRYPT_ROUNDS=4`. Env: `CONCURRENCY_THREADS`, `CONCURRENCY_PORT`, `CONCURRENCY_DB_DATABASE` |
| `Tests\Concurrency\ConcurrencyTestCase` | No transaction (the server must see the fixtures): fixtures are committed as `app`. `migrate:fresh` as `migrator` once per run, and only on a schema ending in `_concurrency`. `assertServerSees($room)` fails clearly if the server is on another schema |
| `Support\Racer` / `Http` / `Race` | A racer is one user with a cookie jar: GET `/login`, POST credentials with `X-XSRF-TOKEN` (the `XSRF-TOKEN` cookie, URL-decoded). `Http::fire()` adds every curl handle to one `curl_multi` before releasing them. `Race::run()` prints the server-side timeline and asserts overlap |
| Overlap proof | `App\Http\Middleware\ExposeRequestTiming` on the reserve route adds `X-Request-Start` (`LARAVEL_START`) and `X-Request-End`, only when `config('concurrency.probe')` is on **and** the app isn't in production (`App\Support\Concurrency\Probe`; `tests/Feature/Concurrency/ProbeSwitchTest.php` boots a production app with the switch set). Each race asserts last start < first end, by the server's clock |
| CI | The `concurrency` job in `_tests.yml`: builds the dev image (`--build-arg UID=$(id -u)`), `composer install` in it, `npm run build` on the runner (racers GET `/login`, which needs built assets), creates the schema with the SQL file, then `docker run --network host … bin/test-concurrency`, so the server reaches the database service on 127.0.0.1. About 4 minutes, most of it the image build |

Each scenario repeats 5 times (`->repeat(ROUNDS)`) with new rooms and renters; move-in is today + 14 days. A failure names what broke: `DOUBLE-BOOKED: …`, `LIMIT BROKEN: …`, `Unexpected response: … HTTP 500`, or the no-overlap message.

### Isolation (slice 3.1)

[docs/ACCESS.md](docs/ACCESS.md#how-it-works-slice-31) is the spec: when the scope applies, the named escapes, the write guard, the policies.

| Piece | Where |
| --- | --- |
| Current account | `App\Support\CurrentLandlordAccount` (scoped binding in `AppServiceProvider`): `account()`, `id()`, `role()`, `isOwner()`, `exists()`, `applies()`. From the logged-in user's membership only (`App\Support\LandlordMembership::of($user)`, cached on the user's `landlordMember` relation). Throws `App\Exceptions\Isolation\NoCurrentLandlordAccount` without one |
| Scope | `App\Models\Scopes\LandlordAccountScope`, added by `App\Models\Concerns\BelongsToLandlordAccount` on the nine models ACCESS.md lists. Landlord user → `where <table>.landlord_account_id = ?`; landlord user without a membership → `1 = 0`; anyone else → nothing. The trait's `saving` hook fills the account and throws `CrossAccountWrite` for another account |
| Escapes | `Property::published()`, `Room::listed()`, `Space::listed()`, `Reservation::onListedSpaces()`, `Reservation::ownedByRenter($user)`, and (4.3) `LandlordInvitation::forToken($token)`. Anything else that calls `withoutGlobalScope(s)` fails `tests/Arch/LandlordScopeTest.php` (lists `NAMED_ESCAPES` and an empty system allowlist) |
| Policies | `app/Policies/*Policy.php`, one per landlord-owned model plus `LandlordMemberPolicy`, built from `App\Policies\Concerns\ChecksAccess` (`landlordOn`, `landlordRole`, `renterOwns`, `renter`). Cross-account / cross-renter → `denyAsNotFound()`; wrong role → `deny()` (403). Ability names follow the matrix rows; `ShowReservation` authorizes `viewOwn` |
| Matrix test | `tests/Feature/Docs/PermissionMatrixTest.php`: `MATRIX_ABILITIES` maps each ACCESS.md row to abilities; `MATRIX_CELLS` lists the cell texts it understands. Adding a policy method without mapping it fails the test |
| Audit | `App\Support\AuditLogger::record($action, $subject, $before, $after)`: `subject.verb` names, only changed fields, `SECRET_FIELDS` stored as `[redacted]`, returns null when nothing changed. `landlord:verify` records `account.verified` / `account.verification_revoked` and one `property.unpublished` per property, actor null |
| Worker | `Queue::looping` → `Auth::forgetGuards()` in `AppServiceProvider`, so a job can't leave a user logged in for the next one |

Tests: `tests/Feature/Isolation/` (scope per model, fail closed, joins, relations, route binding, console and worker; write guard; current account from input and across requests and jobs; audit logger; the pre-3.1 queries with a landlord logged in). The worker tests run `queue:work database --stop-when-empty` inside the test transaction, with the probe job in `tests/Feature/Isolation/Support`.

### Landlord pages (slice 3.2)

[docs/ACCESS.md](docs/ACCESS.md#the-landlord-pages-slice-32) lists the pages and the matrix rows they implement.

| Piece | Where |
| --- | --- |
| Routes | `routes/web.php`, group `landlord.`: `reservations.index/show`, `properties.index/show`, `members.index`, `invitations.store`. Invokable controllers in `app/Http/Controllers/Landlord` (`ListReservations`, `ViewReservation`, `ListProperties`, `ViewProperty`, `UpdateRoomRate`, `ListMembers`, `StoreInvitation`); record parameters are implicitly bound, so the scope makes another account's id a 404 |
| Actions | `CreateInvitation` (locks the account row, returns `App\Data\NewInvitation` with the one-time plain token, audit `invitation.created`). Exceptions: `CannotInvite` |
| Requests | `StoreInvitationRequest` (3.2's `ChangeRoomRate` and its request became `UpdateRoom` in 4.1); it authorizes in `authorize()`, which runs before validation |
| Pages | `resources/js/Pages/Landlord/{Reservations,Properties,Members}`, `Layouts/LandlordLayout.tsx` (menu from the shared `auth.user.landlord_role`), `Components/PropertyState.tsx`. Tables use `.stack-table` (`_components.scss`): one `<table>` that becomes stacked cards below `md`, each `<td data-label="…">` |
| Policies | New `viewAny` on `PropertyPolicy` and `LandlordInvitationPolicy` (the members page), mapped in `PermissionMatrixTest` |

**T6** ([tests/Feature/Isolation/T6CrossAccessTest.php](tests/Feature/Isolation/T6CrossAccessTest.php)) walks `Route::getRoutes()`, takes every route behind the `landlord` middleware and every one behind `renter`, and for each one with a record parameter calls it with another account's (or renter's) record: 404, none of B's identifying text in the response, every table byte-for-byte unchanged (audit log included). A positive control calls the same route on the actor's own record, so a typo can't pass as a 404. It runs as owner A, caretaker A (404, not 403) and renter A, and prints the routes covered. **To extend it** (every slice that adds a record route): add the parameter name to `T6_PARAMETERS` (a `foreign` and an `own` record per context, built in `t6World()`), a valid body to `T6_BODIES` for any non-GET route, and any new secret text to `t6Secrets()`. An unmapped parameter or body **fails the test** with that instruction (`T6: fails for a route parameter nobody mapped…` proves it). A test also pins today's record-route list, so adding one makes you update it on purpose. **T7** is [T7RoleLimitsTest](tests/Feature/Isolation/T7RoleLimitsTest.php). Helpers in `tests/Pest.php`: `landlordUserOf($account, $role)`, `accountIdOf($user)`, `auditRows($action)`.

Phase 3 demo: [e2e/isolation.spec.ts](e2e/isolation.spec.ts) (read-only, both viewports, runs against the production stack too); `bin/smoke-production` checks owner2 gets 404 on `SMOKE_OWNER_RESERVATION_ID`, which the Production workflow reads from the seeded data.

### Landlord editing (slice 4.1)

[docs/ACCESS.md](docs/ACCESS.md#the-landlord-pages-slice-32) lists the pages and rows; [docs/DATA_MODEL.md](docs/DATA_MODEL.md) the new columns (`properties.payment_instructions`, `properties.first_published_at`). The address fields changed afterwards: see [UI review after 4.2](#ui-review-after-42-choreui-review-pass).

| Piece | Where |
| --- | --- |
| Dashboard | `DashboardController`: four figures built from `Reservation` scopes `holdExpiringWithin`, `movingInWithin`, `proofWaitingOver`, `underWayToday`, each linking to `ListReservations` (`?status=` / `?show=`, thresholds are constants there). Indexes were enough (EXPLAIN in the PR) |
| Actions | `CreateProperty`, `UpdateProperty`, `CreateRoom`, `UpdateRoom` (replaces `ChangeRoomRate`), `AddSpace`, `RenameSpace`, `ArchiveSpace/Room/Property`, `UnarchiveSpace/Room/Property`. Value objects `App\Data\PropertyDetails`, `RoomDetails`; trait `Actions\Concerns\ManagesSpaces` (bed labels, 2–12); `Support\BlockingReservations` (locking reads for the archive guard), `Support\Pesos`, `Support\Places` |
| Locks | Every action that archives, changes a fee or type, or adds, renames or unarchives a space takes the **room row `FOR UPDATE` first**, then reads with locking reads (`ArchiveLockingTest` pins the first statement). `ArchiveProperty` locks all its rooms by id, then the property |
| Routes | `routes/web.php`, 16 new routes under `landlord.`; controllers named `New…`/`Edit…`/`Store…`/`Save…` and `Archive…Controller`/`Unarchive…Controller` (the `Controller` suffix only where the name would clash with the action) |
| Forms | `Pages/Landlord/Properties/Form.tsx`, `Rooms/Form.tsx`; `Components/Form/{SelectField,TextAreaField,CheckboxGroup}`, `Components/ActionButton`. Props from `Support\PropertyFormProps` / `RoomFormProps` |

**T6/T7** are extended: T6's world has a shared `roomA` and a Cebu `propertyA`; `T6_OWN_REFUSED` lists the archive routes whose own-record control is the business rule's `422` (the world's records carry a blocking reservation). T7 walks every non-GET `landlord` route and fails for one it doesn't list. **Concurrency:** `tests/Concurrency/ArchiveRaceTest.php`, with `Exchange::after()` to sample both orders.

### Photos (slice 4.2)

[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md#runtime-topology) has the pipeline in two sentences, [docs/DATA_MODEL.md](docs/DATA_MODEL.md) the columns (`alt`, `width`, `height`; `path` is a stem), [docs/ACCESS.md](docs/ACCESS.md#the-landlord-pages-slice-32) the routes and limits, [DEPLOY.md](DEPLOY.md#storage-for-photos) the storage needs.

| Piece | Where |
| --- | --- |
| Pixels | `App\Support\Photos\PhotoProcessor` (plain GD, no library): `probe()` reads type and size from the header, `decodes()`, `process($bytes)` → `App\Data\ProcessedPhoto` (thumb 480 px wide, full 1600 px on the long side, never enlarged, WebP quality 80). Scales first, then applies the EXIF orientation. Limits are its constants: 10 MB, 800 px minimum long side, 40 megapixels. `UnreadablePhoto` = no retry can help |
| Validation | `App\Rules\PropertyPhotoFile` in `PropertyPhotoUploadRequest`: by content, and the whole file must decode |
| Files | `PropertyPhoto::DISK` (`photos`, public): `<path>-thumb.webp`, `<path>-full.webp`. `PropertyPhoto::UPLOAD_DISK` (`proofs`, private): the upload at `uploads/tmp/<uuid>` until the job has run. **Never a local temp file**: `app` and `worker` are separate containers in production (`UploadTest` pins it) |
| Actions | `UploadPropertyPhoto`, `RetryPropertyPhoto`, `DescribePropertyPhoto`, `MovePropertyPhoto`, `DeletePropertyPhoto` (each one audit row: `photo.uploaded`, `.retried`, `.described`, `.moved`, `.deleted`), and the job's two, which write none: `ResizePropertyPhoto`, `FailPropertyPhoto`. Upload, move and delete lock the **property row first**. Trait `Actions\Concerns\StoresPhotoUploads` |
| Job | `App\Jobs\ProcessPropertyPhoto` (3 tries, backoff 10 s and 60 s, after commit). It only loads the photo and calls the actions, so the arch test "status is written only in `App\Actions`" holds unchanged. Idempotent; if the row was deleted or re-uploaded meanwhile, it removes what it wrote |
| URLs | `$photo->thumb_url`, `$photo->full_url` (null until ready) = `Storage::disk('photos')->url()` = `/media/photos/<path>-….webp`, relative, in every environment: with `MEDIA_STORAGE=local` the route `media.photos.show` (`Media\ShowLocalPhoto`, registered only for the local driver) streams the file, in production Caddy serves the bucket. So it works in Codespaces without `storage:link`, and the CSP's `img-src 'self'` covers it. `AWS_PHOTOS_URL` overrides the base (another origin needs the backlog's CSP item) |
| Page | `resources/js/Components/PropertyPhotos.tsx` on `Landlord/Properties/Show`: one request per chosen file, `usePoll(2000, { only: ['photos'] })` while any photo is `processing` (`photos` is a closure prop in `ViewProperty`), Up/Down buttons, a `Modal` to confirm a delete. Styles: `.photo-grid`, `.photo-card` |
| Routes | `landlord.properties.photos.store`, `landlord.photos.update`, `.move`, `.retry`, `.destroy`. **Keep the DELETE registered last**: T6 calls them in order on one photo |

Tests: `tests/Feature/Photos/` (processor, upload validation, the job, managing). Helpers in `tests/Pest.php`: `photoBytes($w, $h, $format)` (left half red, right half blue), `jpegWithExif($orientation)` (orientation + GPS), `photoUpload()`, `photoColourAt()`, and `fakeMediaDisks()`. `tests/TestCase.php` fakes both media disks for every Feature test (a test that seeded `DemoSeeder` used to leave 400 files in `storage/app`); tests that count files call `fakeMediaDisks()` to start empty. T6's snapshot now includes every file on both disks; T7 lists the five write routes. E2E: [e2e/photos.spec.ts](e2e/photos.spec.ts) (needs the `worker`; fixtures in `e2e/fixtures/` are the painter's own drawings).

To see a failed photo by hand: `docker compose stop worker`, upload, replace the file under `storage/app/private/proofs/uploads/tmp/` with text, `docker compose start worker`.

### UI review after 4.2 (`chore/ui-review-pass`)

[docs/DESIGN_SYSTEM.md](docs/DESIGN_SYSTEM.md) is the spec; `/styleguide` shows every piece.

| Piece | Where |
| --- | --- |
| Surfaces | The page is `bg`; content sits on white cards (`$card-bg`). Tables: `<Card className="table-card"><Table className="stack-table">`. Forms: `form` > `Card` > `section.form-section` (a `div` with the title and intro, then `div.form-section__fields`; two columns from `lg`) + `div.form-actions`. Buttons are right-aligned with the saving or deleting one last (Cancel, then Save); `.button-row` does the same for buttons under a form or on a card. Label/value pairs: `dl.details.row` |
| Controls | One height: `--rv-control-height` (40 px, 44 px under `pointer: coarse`) as `min-height` on `.btn`, `.form-control`, `.form-select`; `--rv-control-height-sm` for `.btn-sm`. One radius (`$rv-radius`) for both button sizes and fields. `.btn` is `inline-flex` with a gap, so an icon goes in as a sibling of the text. Never put a `btn-sm` next to a full-size control (`ActionButton` takes `size="md"` for page headers) |
| Buttons | `primary` (one per view), `outline-primary` (everything else), `outline-danger` (opens a confirmation), `danger` (confirms it). No `outline-secondary`, no `btn-link` |
| Links | Underlined in a sentence; standing alone: `.link-plain` (ink), `.link-action` (ube), `.back-link` (via `PageHeader`) |
| Messages | `Components/FlashMessages`: `FlashToast` (the `flash.status` confirmation: a toast under the header that closes itself after 6 s, `TOAST_MS`) and `FlashError` (`flash.error`: stays, below the menu, scrolls into view and takes focus). Both rendered by `PublicLayout`, which takes the landlord menu as its `subnav` prop so the error comes after it. "Closed" is remembered per `flash` object, so the same sentence shows again after the next action |
| Lists that grow | The action comes first: on the members page the invite form (`form.inline-form`: one field and its button on a line) is above the waiting invitations and the members, each heading with its count |
| Components | `Components/PageHeader` (back link, eyebrow, title, subtitle children, actions), `UserMenu` (the header's account menu: a disclosure button, not a Bootstrap dropdown, so no Popper and no inline styles for the CSP), `Pager`, `EmptyState` |
| Dropdown list | `@supports (appearance: base-select)` in `_components.scss` styles the open list of every `.form-select` in Chromium (which is what Playwright and axe run); other browsers keep their native list |
| Address | `properties`: `address` (street, nullable), `barangay`, `city` + `city_slug`, `province`, since 4.4 copies of the linked `city_id` / `barangay_id` ([Places](#places-slice-44)). `$property->full_address`. No `lat`/`lng`. The form has no slug field (`PropertyRequest` still accepts `slug`) |

E2E: the account menu is closed by default, so `Log out` is hidden until `openAccountMenu(page)` ([e2e/support.ts](e2e/support.ts)).

Quirks:

- **`dropColumn` on a column a CHECK names fails** on MySQL and MariaDB: drop the constraint first (`ALTER TABLE … DROP CONSTRAINT`, which both accept).
- **`DemoSeeder` still draws the two numbers that were `lat` and `lng`** and throws them away, so every later Faker draw (and the rest of the demo data, which the e2e tests and README name) is unchanged.
- **A flex `td` (`.stack-table` on a phone) lays its children side by side**: wrap two stacked lines in one `<span>`.
- **Text in SSR HTML is escaped** (`'` → `&#x27;`): Pest `toContain()` on a sentence with an apostrophe fails. Assert a part without one.
- **An axe run earlier in a test leaves a CSP error in `errors`** (its injected `<style>`): clear the list (`errors.length = 0`) before asserting it again later in the same test.
- **Disabled buttons keep full opacity** and use their own muted colors (`--bs-btn-disabled-*`), so the pager's disabled `span.btn.disabled` (not a real `disabled` control, which axe would skip) still passes the contrast check.

### Places (slice 4.4)

[docs/DATA_MODEL.md](docs/DATA_MODEL.md#places-slice-44) is the spec (tables, the copies, names and slugs, the edge cases); [database/data/psgc/README.md](database/data/psgc/README.md) the source and how to update it; [DEPLOY.md](DEPLOY.md#a-newer-psgc-publication) the deploy side.

| Piece | Where |
| --- | --- |
| Data | `database/data/psgc/{provinces,cities,barangays}.csv` (1.5 MB, committed), made by [bin/psgc-convert](bin/psgc-convert) from the PSA workbook in the git-ignored `database/data/psgc/source/` (`docker compose exec app bin/psgc-convert <xlsx>`; plain PHP with zip and xmlreader). Its `INDEPENDENT_CITIES` maps the 18 cities outside every province, checked against the workbook's "(excluding CITY OF …)" notes; `UNLEVELLED` handles the rows without a level (the Special Geographic Area) |
| Names | `App\Support\Places\PlaceNames`: `clean()` (spaces, the PSA's footnote markers such as `Lucenaa/`), `city()` ("City of X" → "X", or "X City" when X is a province, the start of one or another place's name; inner "De"/"Del" lowercase), `district()` ("Tondo I/II" → "Tondo") |
| Tables | `provinces`, `cities` (`kind`: `CityKind`), `barangays` (`district`, Manila only). Models `Province`, `City`, `Barangay` (`label` = "Barangay 395, Sampaloc"), factories for all three. Reference data: no scope, no policies |
| Import | `php artisan places:import [--path=]` → `App\Support\Places\PlacesImporter`: checks the three files, then one transaction of chunked upserts by `psgc_code` (about 1.3 s into an empty schema, 2 s on a rerun). Slugs only for new rows (`PINNED` for the seven old ones). Reports places that left the files (kept) and links unlinked properties (allowlisted in `tests/Arch/LandlordScopeTest.php` for its `DB::table('properties')`). Run by `bin/setup`, `deploy.sh`, `make places`, `make fresh` |
| Property | `city_id`, `barangay_id` (nullable FKs); relations `linkedCity()` / `linkedBarangay()` (not `city()` / `barangay()`, which the text columns would hide); `hasCompleteAddress()`. `App\Support\Places\AddressCopies::of($barangay)` writes the links and the copies, in `CreateProperty`, `UpdateProperty` and `PropertyFactory::at()` |
| Form | `PropertyRequest` takes `city_id` + `barangay_id` (the barangay must be the city's, error on `barangay_id`) and passes the `Barangay` in `PropertyDetails`. `PropertyFormProps`: `provinces`, plus an existing property's `cities` and `barangays`, `barangay_text` / `city_text` for an unlinked one. `Form.tsx`, in the order province, city, barangay, street: `PlaceSelect` (disabled with the reason, loading, error with retry) around `Components/Form/ComboboxField` (a dropdown you can type into, on Downshift's `useCombobox`: `filterOptions()` matches every word, accents ignored; options with a `group` get a heading and an `aria-label` "Barangay 395, Sampaloc"), and `usePlaceList()` (fetch + per-URL cache seeded from props) |
| Endpoints | `GET /places/provinces/{province:slug}/cities`, `GET /places/cities/{city:slug}/barangays` ([routes/places.php](routes/places.php), loaded in `bootstrap/app.php` **without** the `web` group: no session cookie, `Cache-Control: public, max-age=604800`, `throttle:places` 120 a minute per IP). Lists from `App\Support\Places\PlaceLists`, sorted in PHP (natural order, district first) |
| Publish | `PublishChecklist` item `address` ("A complete address") for properties from before 4.4 |

Tests: `tests/Feature/Places/` (`PlaceNamesTest`, `ImportTest` on the fixture in `tests/Fixtures/psgc/` plus one run of the real files, `EndpointsTest`, `AddressCopiesTest`). Helpers in `tests/Pest.php`: `placeIn('manila')` (the seven demo cities with their real names; a second argument names another barangay), `placeFields($barangay)` (`city_id` + `barangay_id` for a form body), `importDemoPlaces()`; `Property::factory()->at($barangay)` and `->unlinked()`. Every factory property gets a made-up place of its own (PSGC codes starting `99`). E2E: `chooseAddress(page, LOYOLA_HEIGHTS)` and `chooseFrom(page, label, option, typed)` in [e2e/support.ts](e2e/support.ts).

### Listing pages (slice 5.1)

[docs/ACCESS.md](docs/ACCESS.md#public-pages-slice-51) lists the public pages and what never appears on them; [docs/DESIGN_SYSTEM.md](docs/DESIGN_SYSTEM.md) has `ListingCard`, `ListingPhotos` and `StickyReserveBar`. **No map in v1**: the address plus an "Open in Maps" link (a Google Maps search of the address text).

| Piece | Where |
| --- | --- |
| Routes | `routes/web.php`: `GET /` (6 newest), `/listings` (`ListListings`, 12 a page), `/listings/{city}/{slug}` (`ShowListing`, name `listings.show`), `/sitemap.xml`, `/robots.txt` (a route: the static file is gone), and `/rooms/{room}` → `RedirectRoom` (`301` to `#room-<id>`, keeps `move_in` and `months`). `POST /rooms/{room}/reservations` is unchanged |
| Public scopes | `Property::published()` finds the listing (city slug + slug; else `404`), `Room::listed()`, `Space::listed()`, and the new `PropertyPhoto::shown()` (ready photos of a published property, cover first; the seventh named escape, in `NAMED_ESCAPES`) |
| Support | `App\Support\Listings\ListingCards::of($properties)` (cover + cheapest room, two queries for any number of properties; `url()` is the one place that builds a listing path), `ListingSeo::for()` (title, description, canonical, og image, JSON-LD, nonce), `Stay::fromRequest()` (`?move_in=&months=`, bad values fall back), `SitemapCache` (hour-long cache, forgotten after commit by model events in `AppServiceProvider::forgetSitemapWhenListingsChange()`: any `Property` or `LandlordAccount` save or delete), `Pesos::format()` (server-side "₱6,500") |
| Page | `Pages/Listings/Show.tsx` (the `<Head>` with `head-key`s: description, canonical, Open Graph, `twitter:card`, JSON-LD; one GET form "Choose your stay", then a card per room with its own plain `<form method="post">` carrying `_token`, `move_in` and `months`), `Pages/Listings/Index.tsx`, `Components/{ListingCard,ListingPhotos,StickyReserveBar}.tsx`, `lib/labels.ts`. The `csrf` prop is the page's token (the forms are not Inertia) |
| Title | `app.tsx`'s title callback leaves a title that already ends in the app name alone, so the SEO title is "Name · Rooms from ₱X in Barangay, City \| ReserveVision" (≤ 80 characters; barangay, then price, then place are dropped until it fits) |
| Plain form posts | `BusinessRuleException::render()` redirects a plain POST that has a session and a `Referer` back with the `error` flash (it was a 422 page); a post with no `Referer` still gets the 422 page |

Tests: `tests/Feature/Listings/` (`ListingPageTest`, `ListingSeoTest`, `SitemapAndRobotsTest`, `ListingsIndexTest`, **T11** `T11ListingWithoutJsTest`), `tests/Feature/Rooms/RoomRedirectTest.php`. Helpers in `tests/Pest.php`: `listedProperty()` (Casa Verde: a private room at ₱6,500, a 4-bed room at ₱3,500, two ready photos). E2E: [e2e/listings.spec.ts](e2e/listings.spec.ts) (read-only; the JavaScript-off test runs axe through `expectNoAxeViolationsWithoutScripts()` in [e2e/support.ts](e2e/support.ts) and posts a plain form as renter2 with a far-off move-in, which creates nothing), [e2e/reserve.spec.ts](e2e/reserve.spec.ts) (the race, through the listing page). `bin/smoke-production` checks the last-bed listing, the sitemap, `robots.txt` and the `/rooms/1` redirect. The concurrency suite still only posts; its `assertServerSees()` probe now GETs the room's listing page (the old `/rooms/{id}` is a redirect).

### Quirks hit in 5.1

- **`test.use({ javaScriptEnabled: false })` also applies to `browser.newContext()`** in the same test: pass `javaScriptEnabled: true` for a context that has to run JavaScript (the login, axe). axe itself can't run in a page with JavaScript off (`evaluate` hangs), so the server's HTML minus its `<script>` tags is judged in a normal page with a `<base>`.
- **React 19 SSR writes `fetchPriority` and `srcSet` in camelCase**; browsers read them case-insensitively, tests use `/fetchpriority/i`.
- **Inertia's `<Head>` doesn't flatten a fragment**: `{cond && <><meta/><meta/></>}` loses its `head-key`s. One conditional per tag.
- **An inline JSON-LD `<script>` through `<Head>` is written raw** (not HTML-escaped), so the JSON must be encoded with `JSON_HEX_TAG | JSON_HEX_AMP` (a property named `</script>` can't close the block); the nonce goes on the tag.
- **`DB::afterCommit()` runs at once inside Pest's `DatabaseTransactions` transaction**, so the sitemap's cache invalidation is testable without committing.
- **A seeded database has e2e-made listings after a few phase 4 runs**, so the last-bed house is not among the 6 on the home page (it is the oldest): the e2e test goes home → the first card, and opens the last-bed listing by URL.
- **A form with `method="get"` and an `action` with a `#fragment`** keeps the fragment (the free-beds form returns to `#rooms`).
- **`ListingCard`'s heading level is a prop**: `h3` under the home page's `h2`, `h2` under the list's `h1` (axe `heading-order`).

### Quirks hit in 4.4

- **The PSA site is behind a Cloudflare challenge**: `curl` and fetch tools get `403`. Download the workbook in a browser.
- **PHP turns numeric string keys into ints** (`'1380600000'` but not `'0730600000'`): the converter, the importer and `importDemoPlaces()` cast keys back with `(string)` wherever they iterate a code-keyed array.
- **`XMLReader::read()` plus `next()` skips every other element**: loop with `next('row')` only, after moving to the first one.
- **Laravel's `upsert()` on MySQL is `ON DUPLICATE KEY UPDATE` on any unique key**, not only the `uniqueBy` columns: a new row whose slug collided with another row's would update that row. The importer only ever writes slugs it has checked are free.
- **A relation named like a column is hidden by it**: `$property->barangay` is the text. The relations are `linkedCity` / `linkedBarangay`.
- **The places tables' ids differ from run to run in Pest**: auto-increment counters survive rolled-back tests, so `DemoSeederTest`'s hash reads a property's city and barangay by PSGC code, and no test assumes a place's id.
- **Typing into a combobox before hydration does nothing** (seen in e2e under load, where `networkidle` came before hydration): `chooseAddress()` retries the province, each attempt with a 3 s timeout, until the city opens up. Without the per-attempt timeout one attempt waits for an option that never comes and uses up the whole retry window.
- **Downshift labels the listbox with the field's label too**, so `getByLabel('Province')` matches two elements: use `getByRole('combobox', { name: 'Province', exact: true })`.
- **A visually hidden span in an option adds a space to its accessible name** in Chromium ("Barangay 395 , Sampaloc"): the option gets an `aria-label` instead (it contains the visible text, so axe's label/content rule passes).
- **`select2` and react-select don't fit the CSP**: one sets inline `style=` positions (and needs jQuery), the other injects CSS-in-JS. Downshift is headless; every style is a class in `_components.scss`.
- **"Choose a city first." is the disabled field's placeholder**, so it isn't repeated as help text (a screen reader would read it twice).

### Publish and invites (slice 4.3)

[docs/ACCESS.md](docs/ACCESS.md#accepting-an-invitation-slice-43) has the acceptance cases, the publish rule and the member rules; [docs/DATA_MODEL.md](docs/DATA_MODEL.md) what "pending" means.

| Piece | Where |
| --- | --- |
| Publish | `App\Support\PublishChecklist::of($property, locking: false)`: the items (`key`, `label`, `done`, `hint`, `fix`) the property page shows and `PublishProperty` re-checks; add a publish rule there and both follow. `PublishProperty` locks the account (shared), every room by id, then the property (`ArchiveProperty`'s order), and in locking mode every read is a locking read with no subquery (`PublishTest` pins the statements). `UnpublishProperty` locks the property row only. Audit `property.published`, `property.unpublished`. Exception `CannotPublish` |
| Page | `ListingCard` in `Pages/Landlord/Properties/Show.tsx` (`ul.checklist`); `checklist` is a closure prop, null unless an owner looks at an unpublished property, and `PropertyPhotos` polls `only: ['photos', 'checklist']`, so a photo becoming ready ticks its item |
| Invitation email | `App\Mail\Landlord\InvitationMail::for(NewInvitation)` (queued **and encrypted**: the plain token is in the job), view `resources/views/mail/landlord/invitation.blade.php`. Queued by the controllers (`StoreInvitation`, `ResendInvitationController`) after the action's transaction |
| Actions | `ResendInvitation` (new token, 7 more days, `invitation.resent`), `RevokeInvitation` (`invitation.revoked`), `AcceptInvitation` (`invitation.accepted`, actor = the new member), `RemoveMember` (`member.removed`). Trait `Actions\Concerns\ChecksInvitees`. Exceptions `InvitationNotValid` (one message, `MESSAGE`), `CannotJoin`, `CannotRemoveMember` |
| Locks | `AcceptInvitation`: the invitation row `FOR UPDATE` (found by `LandlordInvitation::forToken()`), then the user row. Resend and revoke take the same invitation lock. `RemoveMember`: every member row of the account, by id, and it reads its rules from those rows |
| Acceptance | `Invitations\ShowInvitation` (`state`: `invalid`, `register`, `login`, `join`, `refused`, `other_user`) and `Invitations\AcceptInvitationController` + `AcceptInvitationRequest` (the sign-up rules only when nobody is logged in). Page `Pages/Invitations/Show.tsx`. Routes `invitations.show` / `invitations.accept`, outside the `landlord` group, with the `invitations` and `invitation-accept` rate limiters (`AppServiceProvider::INVITATIONS_PER_MINUTE`, 10 per IP) |
| Scope escape | `LandlordInvitation::forToken($token)`, the sixth named escape (`NAMED_ESCAPES` in `tests/Arch/LandlordScopeTest.php`) |
| Members page | `Components/ConfirmButton` (an `outline-danger` button that opens a dialog with the `danger` one) for Remove and Cancel invitation; `ActionButton` for Send again. `td.stack-table__actions` is a table row's button cell |
| Operator | `php artisan landlord:invite {slug} {email}`: a caretaker invitation in the first owner's name; prints the link, sends nothing |
| Shared | `App\Rules\PhilippineMobileNumber` (also used by `CreateNewUser` now); `AuditLogger::record(…, actor: $user)` for an actor who isn't logged in yet; `CurrentLandlordAccount::forget()` |

Tests: `tests/Feature/Landlord/PublishTest.php`, `MembersTest.php`, `InvitationTest.php` (email, resend, revoke), `tests/Feature/Invitations/AcceptInvitationTest.php` (every case, token guessing, the rate limits), `tests/Feature/LandlordInviteCommandTest.php`, and `tests/Concurrency/MembersRaceTest.php` (3 sign-ups through one link; two owners removing each other). T6 maps the five new record routes, T7 the five new writes. Phase 4 demo: [e2e/landlord-phase4.spec.ts](e2e/landlord-phase4.spec.ts) (needs the `worker`; against production it needs `E2E_INVITATION_URL`, and is skipped without it).

To read an invitation by hand in dev: invite from http://localhost:8180/landlord/members as `owner@example.com`, then open http://localhost:8126.

### Quirks hit in 4.3

- **A removed member gets `404`, not `403`, on a page of one record**: route model binding runs before the `landlord` middleware, and the scope matches nothing for a landlord user without a membership. Pages without a record parameter answer `403`.
- **A logged-in landlord user without a membership can't save a landlord-owned row** (the write guard) **and their audit row has no account**. `AcceptInvitation` creates the membership first, calls `CurrentLandlordAccount::forget()`, and only then saves the invitation and the audit row.
- **`withHeaders()` sticks to the test case**: after one `withHeaders(['X-Inertia' => 'true'])` every later request in the test is an Inertia request, and `assertInertia()` on a normal `get()` fails with "Not a valid Inertia response"; with built assets (CI) a later `get()` is a `409` instead (the asset version differs), which the dev stack never shows. Call `$this->flushHeaders()`.
- **The timing probe must wrap `auth` and `landlord`**: a racer refused by a middleware got no `X-Request-Start`, and the race failed for the wrong reason. `bootstrap/app.php` puts `ExposeRequestTiming` before the auth middleware in the priority list.
- **Every racer of the concurrency suite is 127.0.0.1**, so the invitation routes' 10 a minute per IP caps the accept race at 3 rounds of 3 (the test asserts the product).
- **Without the invitation lock the accept race doesn't double-join** (the unique email and `user_id` keys still refuse), it answers `500`. That was the negative control: the lock is what turns the losers into the neutral `422`.
- **`__()` returns `array|string`** for Larastan: a value object that needs `string` narrows it (`PublishChecklist::text()`).
- **A `PUT` of the property form without `payment_instructions` clears them**, and the property can then not be published: T7's update body carries them.
- **`getByRole('cell', { name })` matches two cells in a `.stack-table` row** when the buttons' accessible names contain the same text (an email). Use `td[data-label="Email"]` with `hasText`.
- **A confirmation is gone after 6 seconds**: an e2e assertion on a toast's text must come right after the action, not after an axe run or a screenshot of another page. And the toast is `position: fixed`, so a full-page screenshot shows it over the top of the page.
- **jsdom has no `scrollIntoView`**: `FlashError` calls it as `scrollIntoView?.()`.
- **A read-only field's long value is cut off on a phone** (the invited email on the acceptance page); the full address is in the email the person just opened.

### Quirks hit in 4.2

- **`post_max_size` is 14 MB** (both php.ini files, sized for one proof), so several 10 MB photos can't go in one request: the page sends one request per file. A body over the limit is a bare `413` before any validation.
- **`Storage::fake('photos')` forgets the disk's `url`** and falls back to `/storage/…`, which Laravel's own local-disk route answers with `403`. `fakeMediaDisks()` passes the URL through.
- **GD warnings become exceptions** in Laravel (`HandleExceptions`), so the decode calls use `@` and check for `false`.
- **GD decodes a truncated JPEG** without complaint (`gd.jpeg_ignore_warning=1`, grey where the data ends), so the "doesn't decode" tests use a PNG header on a broken body. Animated WebP doesn't decode, so it is refused.
- **`exif_read_data()` reads JPEG only**: a WebP's or PNG's EXIF orientation is ignored (their metadata is still dropped by re-encoding).
- **A plain lossy WebP from GD is one `VP8 ` chunk**: the no-metadata test asserts the chunk list, not just the absence of the word "Exif".
- **`queue:work` stops itself (exit code 12) above 128 MB**, measured on the whole PHP process. Inside Pest that is the test process, so the in-test workers pass `--memory=1024`, and no test should build a huge image (the 40-megapixel test writes only a PNG header). On a real worker the stop comes after the job, and the container's restart policy brings it back.
- **MySQL returns a JSON column's keys in its own order**: compare decoded audit rows with `toEqual`, not `toBe`.
- **`assert()` fails the arch test** (Pest's security preset), in `database/` too: throw instead.
- **`sizeOf` is PHP's own function** (case-insensitive `sizeof`): a Pest helper can't be called that.
- **A form's `aria-label` equal to one of its field labels** makes Playwright's `getByLabel` match both (strict mode violation).
- **axe reads colours in the middle of a fade**: with React-Bootstrap's `Modal` animation on, the fast production build got `color-contrast` failures on the dialog (blended colours). The delete dialog has `animation={false}`.
- **Running the production stack next to the dev stack needs more than 8 GB**: with both up plus six Playwright browsers this machine swapped, and unrelated tests timed out (static files took 6 s). `docker compose stop` the dev stack before `npm run e2e` against port 8280.
- **On S3, `Cache-Control` is stored with the object** (`PropertyPhoto::WRITE_OPTIONS`), so Caddy needs no header rule and a `404` is never cached as immutable.
- **The local photos disk is under `storage/app/public/photos`**, which is git-ignored; `docker compose down -v` doesn't empty it, but a reseed overwrites the demo files (their paths come from the seeded Faker).

### Quirks hit in 4.1

- **Validation `between` on a `string` field compares the length**; make the field `numeric` for a value check (`lat`/`lng` did, before they were dropped).
- **A slug can't be checked for uniqueness through the scope**, which hides other accounts' properties and `withoutGlobalScope` is forbidden: `CreateProperty` lets the database's unique key decide and retries `-2`, `-3`… on `UniqueConstraintViolationException` (inside a savepoint).
- **Slug and city lock together** once `first_published_at` is set: the city is in the public URL too.
- **Axe `label-content-name-mismatch`**: a button's `aria-label` must contain its visible text ("Archive room Room 1" for "Archive room"), or the page fails.
- **The CSP forbids `style=` attributes** (`style-src-attr`): use a class (`.pre-line`), never `style={{…}}`.
- **`Button as={Link}` doesn't type-check** with Inertia's `Link`: use `<Link className="btn btn-primary">`.
- **`tinker /path/script.php` hangs** in the container; run a standalone script that boots the kernel.
- **Don't run a negative-control `sed` on an untracked file**: `git checkout` can't restore it.
- **Pest tests that call `ownerWithRoom()` twice** need a different `name:` (the slug is unique per city).

### Quirks hit in 3.2

- **A form request validates before its controller runs**, so a controller's `Gate::authorize()` is too late: a caretaker with a bad body got a 302 with errors instead of 403. Authorize in `FormRequest::authorize()`.
- **`landlord_members` has no isolation scope** (the scope itself reads it): `ListMembers` filters by `CurrentLandlordAccount::id()`, and a future `{member}` route relies on `LandlordMemberPolicy` for its 404, which is why T6 maps `member`.
- **Larastan thinks a `belongsTo` property can be null**: the models document `@property-read Space $space` and the like where the foreign key is required.
- **Clicking an Inertia link in e2e right after `goto` can hang** on a cold dev Vite server; the isolation spec reads the link's `href` and `goto`s it.
- **Bootstrap paints table cells with an inset shadow** that shows as a grey strip inside a stacked card: `.stack-table td` resets it.
- **Demo data has 328 reservations on the owner's account**, so the last-bed room's are on the first pages of the `confirmed` filter, not page 1 of the unfiltered list.

### Quirks hit in 3.1

- **Factories are isolated too**: with a landlord logged in (`actingAs`), a factory's lookup of its parent's account (`Property::query()->whereKey(…)->value('landlord_account_id')`) is scoped, and the write guard refuses another account's rows with `CrossAccountWrite`. Create fixtures **before** `actingAs()` (or `$this->app['auth']->forgetGuards()` first).
- **The queue worker resets scoped instances between jobs, but not the auth guards**: a job that logs a user in leaves them logged in for the next job, and the scope follows. Hence the `Queue::looping` reset. `Looping` fires only in the daemon loop (not for `sync` jobs inside a request, not for `--once`).
- **The test HTTP kernel doesn't flush scoped instances between `$this->get()` calls**, so `CurrentLandlordAccount` re-resolves whenever the logged-in user changes instead of trusting its first answer.
- **`withoutGlobalScope()` inside a `whereHas()` callback works**: the has-query's scopes are applied when it is compiled, after the callback. That's how `Room::listed()` uses `Property::published()`.
- **Larastan and global scopes**: a `Scope` needs `@implements Scope<Model>` and `apply()` takes `Builder<covariant Model>`, or Larastan reports a generics or parameter-compatibility error.
- **Pest `arch()->expect('App\Models')->toExtend(Model::class)`** also sees `App\Models\Scopes` and `App\Models\Concerns`: the test ignores both.
- **Artisan output expectations**: each output line satisfies at most one `expectsOutput(ToContain)`, so print one value per line. And Pest's `toContain()` is variadic: a second argument is another expected value, not a failure message.

### Quirks hit in 2.5

- **Removing a lock doesn't make `ReserveSpace` double-book**: its locking reads take shared next-key locks, so parallel inserts deadlock (1213) instead. The negative controls had to drop the locking reads too (ADR 0001, Evidence). Without the room lock the losers get `500` after 3 deadlocks, not "no free space".
- **Client-side timings can't prove concurrency**: a server with one thread still accepts all 10 connections at once, so every request "starts" together from curl's point of view. Only server-side start/end times show it.
- **`/up` has no `web` middleware**, so it sets no session or XSRF cookie: racers GET `/login` for theirs, and that page needs built assets (or `public/hot`) to render.
- **The CSRF bypass in tests depends on `APP_ENV=testing`**: a Feature test that switches the app to `production` gets `419` on posts; exercise the middleware directly instead.
- **FrankenPHP passes the process environment to PHP**: in CI the container gets `DB_HOST=127.0.0.1` with `-e`, which wins over `.env`'s `db`. `php_server { env … }` in a Caddyfile sets `$_SERVER` entries, which Laravel also reads before `.env`.
- **axe's injected `<style>` is a CSP error for the rest of the document**: after an Inertia visit the next page is the same document, so the e2e test clears its error list after each axe run.
- **`Carbon::createFromFormat()` throws on garbage** (`?move_in=tomorrow`) rather than returning false: check the shape with a regex first.

### Quirks hit in 2.4

- **Fortify's named login limiter answers with a bare 429**, which Inertia shows as an error page. With `limiters.login => null` Fortify runs `EnsureLoginIsNotThrottled` in its pipeline instead (5 a minute per email + IP), which throws a validation error on `email`. The `registration` and `password-reset-link` limiters do the same through `Limit::response()` (303 back with an `email` error).
- **Fortify ships routes for everything**, and Fortify 1.42 requires laravel/passkeys. `Fortify::ignoreRoutes()` plus our own `routes/auth.php` keeps the route list to what we use; `php artisan route:list` shows no passkey or 2FA routes.
- **The password broker reveals accounts** ("We can't find a user with that email" and the per-email throttle message). `FailedPasswordResetLinkRequestResponse` answers both like a sent link.
- **Clicking an SSR'd Inertia `Link as="button"` before hydration does nothing** (it's a plain `<button type="button">`). E2E waits for `networkidle` after a full page load before clicking.
- **Adding pages while the Vite dev server runs** can give `504 Outdated Optimize Dep` on the new page chunks in the browser; `docker compose restart ssr`, wait for SSR, rerun.
- **`queue:work` keeps the config it booted with**: after changing mail config (or pulling this slice), `docker compose restart worker`, or mails use the old theme.
- **`DemoSeeder` needs Faker at runtime**, and the production image installs `--no-dev`, so `fakerphp/faker` moved to `require` (it was a 2.2 gap: the documented production seeding could never have run).
- **A `.env` created before 2.2 has no `APP_DEMO` line**: add one to try the demo panel locally.
- **CSP `upgrade-insecure-requests` on a plain-HTTP page breaks Inertia redirects**: Chromium upgrades the follow-up of a same-origin redirect to `https://`, so on CI's HTTP-only production stack a successful login POST (302) never reached `/landlord`. `SecurityHeaders` now sends it only on HTTPS requests in production; `bin/smoke-production` checks it only against an `https://` base URL.
- **The lockout message counts seconds**: rate-limit tests freeze time, or "Wait 59 seconds" makes them flaky.

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

