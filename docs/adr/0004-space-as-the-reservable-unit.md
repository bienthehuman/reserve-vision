# 0004. Make "space" the single reservable unit

- Status: Proposed
- Date: 2026-11-14

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
- Bad: one more table and one more join for availability. Measured in slice 13 with `EXPLAIN` on seeded data.
- Renters never choose between "Bed A" and "Bed B", which keeps the UI simple and stops cherry-picking.
