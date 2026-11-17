# Architecture decision records

One file per decision that a reviewer would ask about, from [the template](0000-template.md). The format and statuses are in [CONVENTIONS.md](../CONVENTIONS.md#adrs). Each ADR was reviewed against the code as built for v1 (slice 7.3): where reality differed, it carries a dated "Update" section rather than a rewritten history.

| # | Decision | Status |
| --- | --- | --- |
| [0001](0001-room-lock-vs-exclusion-constraint.md) | Prevent double-booking with a room-row lock and locking reads, not an exclusion constraint | Accepted |
| [0002](0002-inertia-monolith.md) | Build as an Inertia + React monolith with SSR, not an API + SPA | Accepted |
| [0003](0003-manual-payment-proof.md) | Take reservation fees by uploaded proof, verified by hand; no payment gateway | Accepted |
| [0004](0004-space-as-the-reservable-unit.md) | Make "space" (a whole room or one bed) the single reservable unit | Accepted |
| [0005](0005-no-word-tenant.md) | Avoid the word "tenant" in the code | Accepted |
| [0006](0006-bootstrap-over-utility-first.md) | Use Bootstrap 5 via Sass, not a utility-first framework | Accepted |
| [0007](0007-deployable-not-hosted.md) | Ship deployable, not hosted; prove deployment in CI | Accepted |
| [0008](0008-least-privilege-database-users.md) | Separate least-privilege database users; `audit_logs` append-only by triggers | Accepted |
| [0009](0009-no-map-in-v1.md) | No map in v1: an address and an "Open in Maps" link | Accepted |
| [0010](0010-psgc-places-data.md) | Use the PSA's place list (PSGC), bundled, instead of free-text addresses | Accepted |
| [0011](0011-stream-private-proofs-through-php.md) | Stream payment proofs through PHP behind user-bound signed links, not pre-signed storage URLs | Accepted |
| [0012](0012-fortify-headless.md) | Use Laravel Fortify headless for authentication | Accepted |
| [0013](0013-landlord-verification-by-artisan-command.md) | Verify landlord accounts with an artisan command, not an admin web role | Accepted |
