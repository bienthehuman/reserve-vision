# 0008. Use separate least-privilege database users from the first slice

- Status: Proposed
- Date: 2026-11-14

## Context

On the previous project the app connected as a database superuser until late, and introducing a restricted role then forced test rewrites. The audit log must be append-only.

## Decision

Two users, created by the database init script in dev, CI and production:

| User | Grants | Used by |
| --- | --- | --- |
| `migrator` | all privileges on the app schema | `php artisan migrate` only |
| `app` | `SELECT, INSERT, UPDATE, DELETE` on app tables; **`SELECT, INSERT` only on `audit_logs`**; no DDL | the web app, workers, scheduler, **and the test suite** |

Tests run as `app`. They reset data with transactions (`DatabaseTransactions`); `TRUNCATE` is not available to `app` because MySQL treats it as DDL (it needs `DROP`). The schema is migrated once per run by `migrator`.

## Consequences

- Good: a SQL injection or a bug can't drop tables or rewrite the audit history.
- Good: a missing grant fails in CI, not in production.
- Bad: test setup is slightly more involved (two connections; `RefreshDatabase` replaced by a migrate-once + transaction strategy). This is settled in slice 1.1, while there is almost nothing to change.
