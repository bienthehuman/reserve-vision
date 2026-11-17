# 0004. Make "space" the single reservable unit

- Status: Accepted (2026-11-17)
- Date: 2026-11-14
- Updated: 2026-11-17 (as built in v1, last section)

## Context

Apartments and private rooms are rented whole. Boarding-house rooms are often rented per bed. The thesis version handled these with separate code paths and got availability wrong for shared rooms.

## Options

1. **Reserve rooms, with a capacity count**: overlap check becomes "count of overlapping reservations < capacity". Can't say which bed a renter has, and the counting is easy to get wrong under concurrency.
2. **Reserve spaces**: a room has one or more spaces (whole room, or one per bed). A reservation always points at exactly one space.

## Decision

Option 2. Renters pick a room; the server assigns the lowest-id free space.

## Consequences

- Good: one overlap rule, one index, one lock for both property types.
- Good: landlords see which bed is taken, and can archive a broken bed.
- Bad: one more table and one more join for availability. Measured in slice 5.2 with `EXPLAIN` on seeded data.
- Renters never choose between "Bed A" and "Bed B", which keeps the UI simple and stops cherry-picking.

## Update 2026-11-17: as built in v1

- Measured in slice 5.2 as planned: the availability query on the demo data (about 2,200 reservations on about 400 spaces) runs in about 4 ms with the existing indexes, so **no index was added** ([perf/availability-query.md](../perf/availability-query.md)).
- `ReserveSpace` assigns the lowest-id free space, as decided. A renter sees which bed they got ("Bed D") on the hold page; the landlord sees it on the reservation.
- The single overlap rule paid off twice more: the search (5.2) and the listing page's "1 of 4 beds free" both use the same `Space::freeDuring()` scope, and T10 checks it against a brute-force count.
