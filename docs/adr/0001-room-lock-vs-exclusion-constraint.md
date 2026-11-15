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

### The read snapshot (added in slice 2.3)

A lock only helps if the checks after it read current data. Under InnoDB's default `REPEATABLE READ`, a transaction's read snapshot is fixed by its first non-locking read. If request B runs any plain `SELECT` (a lazy-loaded relation, a refresh) and then waits at the room lock while A inserts the last free space and commits, B's later plain `SELECT`s still read the old snapshot: B sees the space as free and double-books it.

Two ways out: run the transaction at `READ COMMITTED` (each plain read sees the latest commits), or make every read after the locks a locking read (`FOR UPDATE` / `LOCK IN SHARE MODE`, which InnoDB always serves from the latest committed rows). **We use locking reads**, and also take both locks as the first statements of the transaction:

- Good: it works whatever transaction the caller is already in. MySQL can't change the isolation level inside an open transaction (error 1568), so `READ COMMITTED` would quietly not apply when `ReserveSpace` runs nested (every Pest test does, inside `DatabaseTransactions`), and the guarantee would depend on the caller.
- Good: no dependence on server settings. `READ COMMITTED` with statement-based binary logging refuses writes on MySQL.
- Good: the same reads are locking reads on PostgreSQL too (`FOR UPDATE` / `FOR SHARE` read the latest row versions in `READ COMMITTED`, its default).
- Bad: under `REPEATABLE READ`, locking range reads take shared next-key (gap) locks on the `reservations` indexes. Two requests for *different* rooms whose space ids are neighbours in the index can then block each other's insert and deadlock. InnoDB picks a victim and `ReserveSpace` retries it (3 attempts). The limits check also share-locks the rooms of the renter's own open reservations (it joins through them), so it can wait briefly behind a reservation being made in one of those rooms.
- Bad: the rule is easy to break by accident (a relation read on a model, a `NOT EXISTS` subquery, which isn't a locking read even under a locking outer `SELECT`). `tests/Feature/Reservations/LockingTest.php` fails if any `SELECT` in `ReserveSpace` is non-locking or has a subquery, or if the first two statements aren't the renter and room locks.
