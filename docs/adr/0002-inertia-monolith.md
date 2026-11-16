# 0002. Build as an Inertia + React monolith, not an API + SPA

- Status: Proposed
- Date: 2026-11-14

## Context

Public listing pages must be indexable and fast on phones with mobile data. Landlord screens are form-heavy CRUD. One developer builds and maintains it. The companion B2B project already demonstrates the separate API + SPA style.

## Options

1. **Laravel + Inertia + React with SSR**: one deployable app; routes, auth, validation and authorization stay in Laravel; React only for views.
2. **Laravel API + separate React SPA (or Next.js)**: two deployables, token auth, an API contract to version, and SSR via a second framework.
3. **Blade + Livewire**: server-rendered, but no React/TypeScript showcase.
4. **Hybrid**: plain Blade for public pages (pure HTML, no SSR service) and Inertia without SSR for dashboards. Fastest public pages, but two view stacks, so shared pieces like `PriceTag` and `StatusBadge` would be built twice.

## Decision

Option 1. SSR is turned on for public pages; authenticated dashboards render client-side.

## Consequences

- Good: no API layer to design, version or secure for a single client; sessions and CSRF work out of the box; policies apply directly in controllers.
- Good: listings arrive as complete HTML (T11) without a second framework.
- Good: the portfolio shows both Laravel styles across the two projects.
- Bad: a future mobile app would need an API added then. That's acceptable, since native apps are out of scope.
- Bad: the SSR Node process is one more service to run and monitor.
- Bad: listing pages still download and hydrate React, which costs mobile Lighthouse points that Blade + Livewire or the hybrid wouldn't. Mitigations: per-page code splitting, no map script on the listing page (v1 has no map: an address and a link to Google Maps), and Lighthouse recorded in slice 7.1. If the score is poor, option 4 is the fallback for public pages only.
- On requirements alone, Blade + Livewire is leaner. Inertia was chosen because the project must also show React and TypeScript, and contrast with the B2B project's API + SPA. Reconfirmed on 2026-11-14 after comparing all four options.
