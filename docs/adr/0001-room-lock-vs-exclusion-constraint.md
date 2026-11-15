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

## Evidence (slice 2.5)

The concurrency suite (`tests/Concurrency/ReservationRaceTest.php`, `composer test:concurrency`) fires real, parallel HTTP requests at a FrankenPHP server with 16 PHP threads (`docker/concurrency/Caddyfile`), each racer logged in with its own session. Requests are prepared first and released together with `curl_multi`. Every scenario runs 5 rounds with fresh rooms and renters.

**Overlap is proven, not assumed.** Client-side times can't show it: a server that queues requests still accepts every connection at once. So the reserve endpoint reports when the server started (`LARAVEL_START`) and finished each request, in `X-Request-Start` / `X-Request-End`, only while the test-only `CONCURRENCY_PROBE` switch is on, which production ignores (`App\Support\Concurrency\Probe`, `tests/Feature/Concurrency/ProbeSwitchTest.php`). Every race asserts that the last request started before the first one finished, so all of them were inside the server at the same moment. Against a 1-thread server the suite fails with "the server did NOT run the 10 requests at the same time … so this run proves nothing about the locks".

### Results with the locks (as merged)

| Scenario | Expected | Every round, both engines |
| --- | --- | --- |
| T1: 10 renters, 1 single-space room | 1 hold, 9 × "no free space", 1 blocking row | ✓ |
| T2: 6 renters, 4-bed room | 4 holds on 4 different beds, 2 × "no free space" | ✓ |
| T8b: 1 renter, 3 rooms in 3 properties | 2 holds, 1 × "2 open reservations" | ✓ |
| T8b: 1 renter, 2 rooms in 1 property | 1 hold, 1 × "open reservation in this property" | ✓ |

In every round all requests overlapped inside the server, typically for 80–120 ms; the timelines are in the job output. CI runs: RUNS_WITH_LOCKS.

### Negative controls (temporary commits, reverted)

Each control removed one thing from `ReserveSpace::reserve()` and ran the same suite. No artificial delay was needed: the race window is wide enough without one, so the slice adds no test-only hook inside `ReserveSpace`.

| Removed | Result | Runs |
| --- | --- | --- |
| Room lock only (reads still locking) | **No double-booking**, but T2 fails every round: requests die with `500` (deadlock `1213` on all 3 attempts). T1 mostly still passes | NC_ROOM |
| Renter lock only (reads still locking) | T8b still passes: the limits query share-locks the renter's reservation range, the parallel inserts deadlock, and the retry reads the winner's hold | NC_RENTER |
| Room lock **and** the locking reads on spaces/reservations | **Double-booked every round**: T1 ends with 2–4 blocking reservations on one space, T2 with two holds on the same bed | NC_ROOM_READS |
| Renter lock **and** the locking limits read | **Limits broken every round**: T8b ends with 3 open reservations (and 2 in one property) | NC_RENTER_READS |

What this shows:

- The locks are what make the outcome **orderly**: renters queue at the room (or renter) row and the losers get a clear `422`. The locking reads added in 2.3 are a second line of defence: on their own they still prevent double-booking under InnoDB's next-key locking, but only by deadlocking, so under contention users would see errors instead of "no free space". Keep both.
- The suite catches the bug class it exists for: take away the serialization and it fails on both engines, every round.
