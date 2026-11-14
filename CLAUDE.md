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

`bin/setup` (or `make setup`): creates `.env` from `.env.example` if missing, builds the image, `composer install`, `npm ci`, generates `APP_KEY` if empty, `docker compose up -d --wait`, migrates as `migrator`, seeds as `app`, then checks `/` answers. Non-interactive and idempotent; slice 1.3 reuses it for Codespaces.

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
- `audit_logs` (slice 2.1) needs an INSERT-only grant; see the marked TODO in the SQL file for the two options.

### Running the checks

| Suite | Command | Notes |
| --- | --- | --- |
| Pint | `docker compose exec app composer lint` (`make lint`) | `composer format` fixes. Laravel preset + `declare_strict_types` |
| Larastan | `docker compose exec app composer analyse` (`make analyse`) | Level max, no baseline. Covers `app`, `bootstrap/app.php`, `config`, `database`, `routes` |
| Pest | `docker compose exec app composer test` (`make test`) | Runs as `app` on `reservevision_test`. `tests/TestCase.php` runs `migrate:fresh` once per process as `migrator` (refuses any schema not ending in `_test`), then each Feature test runs in a `DatabaseTransactions` transaction. **Never use `RefreshDatabase`.** Feature tests that render pages need SSR: in dev that's the running `ssr` service; `INERTIA_SSR_THROW_ON_ERROR=true` makes a missing SSR server fail loudly |
| Vitest | `docker compose exec ssr npm run test` (`make vitest`) | jsdom; `resources/js/**/*.test.{ts,tsx}` |
| TypeScript | `docker compose exec ssr npm run types` (`make types`) | TS 7 (`tsc --noEmit`) |
| Playwright + axe | `npm run e2e` on the host (`make e2e`) | Needs the stack running and `npx playwright install chromium` once. `E2E_BASE_URL` (default `http://localhost:8180`) points it at another stack; `E2E_SKIP_DEV_PAGES=1` skips `/styleguide` against production |
| Production build | `docker compose exec ssr npm run build` | Client to `public/build`, SSR bundle to `bootstrap/ssr/app.js`. Delete `public/hot` or stop `ssr` to test the built assets |

### Pinned versions

PHP 8.4.27 (FrankenPHP 1.13.5 image), Composer 2.9, Laravel 13.42, inertia-laravel 3.7, Pest 5.3, Larastan 3.15, Pint 1.32. Node 24.22, Vite 8.3, React 19.3, @inertiajs/react + @inertiajs/vite 3.11, TypeScript 7.0, Bootstrap 5.3.9, React-Bootstrap 2.10, react-bootstrap-icons 1.11, Sass 1.108, Vitest 5.1, Playwright 1.65.1 (exact, matches its browser build), @axe-core/playwright 4.14. MySQL 8.4.13, Mailpit 1.33.

### Quirks hit in 1.1

- **The FrankenPHP image has its own `HEALTHCHECK`** (probes the Caddy admin port), which `worker` and `scheduler` inherit. They set `healthcheck: disable: true`, or `up --wait` reports them unhealthy.
- **Vite host check**: the app calls the SSR endpoint as `http://ssr:5183`, so `server.allowedHosts: ['ssr']` is needed (otherwise a 403 and a silent fallback to client rendering). `INERTIA_SSR_HOT_URL` tells Laravel to use that URL instead of the browser-facing one in `public/hot`.
- **Vite CORS**: setting `server.origin` replaces laravel-vite-plugin's CORS list, so `server.cors.origin` is set to `APP_URL` explicitly.
- **React-Bootstrap imports**: import from the package root (`import { Button } from 'react-bootstrap'`). Deep imports (`react-bootstrap/Button`) resolve to CommonJS under Node SSR and fail with "Element type is invalid … got: object".
- **CSP and axe**: axe-core injects a `<style>` that the CSP blocks. The e2e test checks console and CSP errors *before* running axe.
- **Sass**: Bootstrap 5.3 still needs `@import`; `quietDeps` + `silenceDeprecations: ['import']` in [vite.config.ts](vite.config.ts). Our partials use `sass:color` / `sass:map`, not the deprecated globals.
- **Cold Vite dev server**: the first load after `ssr` starts can fail with `504 Outdated Optimize Dep` while Vite discovers dependencies. `optimizeDeps.include` and `server.warmup` in [vite.config.ts](vite.config.ts) prevent this; add new heavy dependencies there.
- **SSR warm-up**: for a few seconds after `ssr` starts, Inertia logs "SSR skipped, module graph is still warming up" and pages fall back to client rendering. `bin/setup` waits until `/` is server-rendered; after a manual `docker compose restart ssr`, wait before running e2e or the Pest SSR test.
- **Playwright on the host** needs `npx playwright install chromium` after any `@playwright/test` version change.
- The MySQL healthcheck connects over TCP as `app`, so "healthy" also means the init script has finished.
