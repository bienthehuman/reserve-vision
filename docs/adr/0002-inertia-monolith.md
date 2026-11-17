# 0002. Build as an Inertia + React monolith, not an API + SPA

- Status: Accepted (2026-11-17)
- Date: 2026-11-14
- Updated: 2026-11-17 (as built in v1, last section)

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

## Update 2026-11-17: as built in v1

- **SSR is on for every page, not only the public ones.** The plan was client-side rendering for the authenticated dashboards, but SSR was never switched off per page: the landlord and renter pages come out of the same SSR server as the listings, and nothing was gained by adding a switch. The public pages are the ones that also work without JavaScript (T11: the listing's Reserve buttons and the search form are plain forms) and carry their own `<head>` for search engines.
- **The mobile Lighthouse cost was measured in slice 7.2, not 7.1.** Every public page scores at least 90 for Performance on the simulated slow-4G phone, with 100 for Accessibility (CI asserts both; [perf/lighthouse.md](../perf/lighthouse.md)). The entry chunk (React DOM plus Inertia, about 113 kB gzipped) is the floor this ADR predicted; it is held by a budget in CI. **The hybrid fallback (option 4) was not needed.**
- **No map** on the listing page, as planned here; the reasons are in [ADR 0009](0009-no-map-in-v1.md).
