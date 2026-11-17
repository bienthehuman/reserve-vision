# 0012. Use Laravel Fortify headless for authentication

- Status: Accepted (2026-11-17)
- Date: 2026-11-15 (slice 2.4)

## Context

The app needs login, logout, renter and landlord registration (a landlord's creates an account and an owner membership in one transaction), email verification, password reset and rate limits, with pages in the project's own React + Bootstrap design ([ADR 0006](0006-bootstrap-over-utility-first.md)) and in its voice. Authentication is security-sensitive code that a portfolio project shouldn't reinvent.

## Options

1. **A Laravel starter kit** (the React kit): copies controllers and pages into the app, styled with Tailwind and shadcn/ui, which would be rewritten for Bootstrap anyway.
2. **Fortify, headless**: Laravel's own authentication backend (controllers, login pipeline, password broker, verification), with no views; the app supplies its pages and its own routes file.
3. **Hand-written controllers** on Laravel's auth primitives: full control, and every edge case (throttling, session fixation, the password broker's account leaks) to get right alone.

## Decision

Option 2. `Fortify::ignoreRoutes()` plus our own `routes/auth.php`, so only the features we use have routes (no 2FA, passkeys or profile pages). Inertia pages in `resources/js/Pages/Auth`. Our own `CreateNewUser` calls `RegisterRenter` or `RegisterLandlord`; our own responses decide where people land and keep a reserve intent across the login.

## Consequences

- Good: the security-sensitive parts are maintained upstream; ours is the product logic (who lands where, the landlord account, the messages).
- Good: two-factor authentication and passkeys can be switched on later without new libraries (backlog).
- Bad: Fortify 1.42 requires laravel/passkeys and the 2FA libraries even when unused.
- Bad: some defaults had to be worked around: its named login limiter answers a bare `429` (we use its in-pipeline throttle instead, shown as a form error), and the password broker reveals whether an email has an account (we answer both cases the same). Both are in [CLAUDE.md](../../CLAUDE.md#quirks-hit-in-24).
- Bad: the login and sign-up forms need JavaScript (Inertia forms); plain form posts are in the backlog.
- Details: [ACCESS.md](../ACCESS.md#logging-in-slice-24).
