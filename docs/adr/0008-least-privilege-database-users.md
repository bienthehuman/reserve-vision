# 0008. Use separate least-privilege database users from the first slice

- Status: Proposed
- Amended: 2026-11-15 (slice 2.1: how `audit_logs` is protected)
- Date: 2026-11-14

## Context

On the previous project the app connected as a database superuser until late, and introducing a restricted role then forced test rewrites. The audit log must be append-only.

## Decision

Two users, created by the database init script in dev, CI and production:

| User | Grants | Used by |
| --- | --- | --- |
| `migrator` | all privileges on the app schema | `php artisan migrate` only |
| `app` | `SELECT, INSERT, UPDATE, DELETE` on the schema; no DDL, no `TRIGGER`. `audit_logs` is append-only through triggers (below) | the web app, workers, scheduler, **and the test suite** |

### Append-only `audit_logs` (decided in slice 2.1)

The first draft said `app` gets `SELECT, INSERT` only on `audit_logs`. MySQL has no per-table `DENY`, and the schema-wide grant already includes `UPDATE` and `DELETE`, so the options were:

| Option | How | Cost |
| --- | --- | --- |
| a) second schema | `audit_logs` in `<db>_audit`, `app` gets `SELECT, INSERT` there | Two schemas to create (and, in tests, five more), back up and restore. `migrate:fresh` doesn't drop the audit table, so it is no longer repeatable without extra code |
| b) per-table grants | Replace the schema-wide grant with one grant per table, after the migrations | Every future migration that adds a table must remember to grant it, or the app fails in production. The SQL file runs before the tables exist |
| **c) triggers (chosen)** | `BEFORE UPDATE` and `BEFORE DELETE` triggers on `audit_logs` signal `SQLSTATE '45000'`, created by `migrator` in the migration | The server must allow trigger creation, see below |

**Why c.** It lives entirely in the migration: no second schema, no per-table grants to forget, `migrate:fresh` and backups (`mysqldump` includes triggers) work unchanged, and it behaves the same on MySQL and MariaDB. `app` has no `TRIGGER` privilege, so it can't drop the triggers, and no `DROP` privilege, so it can't `TRUNCATE`. It is weaker than a grant in one way: `app` still holds `UPDATE` and `DELETE` privileges and is stopped by a trigger rather than by a privilege check. Anyone with `migrator` (or root) can still change history, as with any option.

**The server setting.** With binary logging on (the MySQL 8.4 default), creating a trigger requires `SUPER` unless `log_bin_trust_function_creators=1`. `SUPER` on `migrator` would break the point of this ADR, so the setting is applied where each environment starts its database:

| Environment | How |
| --- | --- |
| Dev (`compose.yaml`), dev container | `command: ["--log-bin-trust-function-creators=1"]` on `db` |
| Production (`compose.prod.yaml`) | the same |
| CI (MySQL and MariaDB service containers) | a `SET GLOBAL` step as root before the migrations (service containers can't take server arguments) |
| An external database | set the parameter in its configuration; the migration fails with a message that says so, and leaves no unprotected table behind |

MariaDB has binary logging off by default and needs nothing; the same `SET GLOBAL` is harmless there.

**Verification.** `tests/Feature/Schema/AuditLogTest.php` proves, as `app`, that `INSERT` and `SELECT` work and that `UPDATE`, `DELETE`, `TRUNCATE`, `DROP TABLE` and `DROP TRIGGER` fail, on both engines in CI. `php artisan app:doctor` attempts an `UPDATE` inside a rolled-back transaction, so a deploy fails if the triggers are missing.

Tests run as `app`. They reset data with transactions (`DatabaseTransactions`); `TRUNCATE` is not available to `app` because MySQL treats it as DDL (it needs `DROP`). The schema is migrated once per run by `migrator`.

## Consequences

- Good: a SQL injection or a bug can't drop tables or rewrite the audit history.
- Good: a missing grant fails in CI, not in production.
- Bad: test setup is slightly more involved (two connections; `RefreshDatabase` replaced by a migrate-once + transaction strategy). This is settled in slice 1.1, while there is almost nothing to change.
