# 0001. Prevent double-booking with a room-row lock, not an exclusion constraint

- Status: Proposed
- Date: 2026-11-14

## Context

No space may hold two blocking reservations with overlapping `[move_in, move_out)` ranges, even when many renters submit at the same instant (T1, T2). The primary database is MySQL 8, with MariaDB in CI.

## Options

1. **PostgreSQL exclusion constraint.** `EXCLUDE USING gist (space_id WITH =, daterange(move_in, move_out) WITH &&) WHERE (status IN (...))`. The database itself rejects overlaps, whatever code path writes the row.
2. **Lock the room row, then check and insert** (`SELECT … FOR UPDATE` on `rooms`, inside a transaction, retried on deadlock).
3. **Unique index on (space_id, night)**: one row per booked night. Enforced by the database on any engine, but it writes about 365 rows per yearly lease and makes changing dates expensive.
4. **Optimistic insert then re-check**: race-prone under `REPEATABLE READ` without gap locks; hard to reason about.

## Decision

Option 2. Every reservation for a room is serialized at the room row, and the overlap check and insert happen while the lock is held. Same-renter limits are protected by also locking the renter row first (always renter → room, to avoid deadlocks).

## Consequences

- Good: it works the same on MySQL, MariaDB, PostgreSQL and SQL Server. The behaviour is easy to read in one action class.
- Good: the lock is per room, so contention is limited to renters wanting the same room, which is exactly the contention that matters.
- Bad: the guarantee lives in application code. A future code path that inserts reservations without going through `ReserveSpace` could break it. Mitigations: an architecture test forbids `Reservation::create` outside `app/Actions`, and the parallel-HTTP tests T1, T2 and T8b run in CI on both databases.
- Bad: the "a hold is blocking only while live" rule makes the predicate time-dependent, which a PostgreSQL partial exclusion constraint couldn't express directly either (`now()` isn't allowed in index predicates). Holds would need a materialized expiry there too.
- If the project moves to PostgreSQL, add the exclusion constraint **as well** (defence in depth) and keep the lock for the clear error message.
