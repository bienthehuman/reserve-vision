# 0002. Build as an Inertia + React monolith, not an API + SPA

- Status: Proposed
- Date: 2026-11-14

## Context

Public listing pages must be indexable and fast on phones with mobile data. Landlord screens are form-heavy CRUD. One developer builds and maintains it. The companion B2B project already demonstrates the separate API + SPA style.

## Options

1. **Laravel + Inertia + React with SSR**: one deployable app; routes, auth, validation and authorization stay in Laravel; React only for views.
2. **Laravel API + separate React SPA (or Next.js)**: two deployables, token auth, an API contract to version, and SSR via a second framework.
3. **Blade + Livewire**: server-rendered, but no React/TypeScript showcase.

## Decision

Option 1. SSR is turned on for public pages; authenticated dashboards render client-side.

## Consequences

- Good: no API layer to design, version or secure for a single client; sessions and CSRF work out of the box; policies apply directly in controllers.
- Good: listings arrive as complete HTML (T11) without a second framework.
- Good: the portfolio shows both Laravel styles across the two projects.
- Bad: a future mobile app would need an API added then. That's acceptable, since native apps are out of scope.
- Bad: the SSR Node process is one more service to run and monitor.
