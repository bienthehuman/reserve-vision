# 0009. No map in v1: an address and an "Open in Maps" link

- Status: Accepted (2026-11-17)
- Date: 2026-11-17 (the decision was taken in the UI review after slice 4.2 and slice 5.1; written up in 7.3)

## Context

A renter wants to know where a place is. The first data model had `lat` and `lng` on properties, typed by the owner, for a map on the listing page. In practice owners don't know their coordinates, typed numbers are easy to get wrong, and the listing page is the one that has to load fast on a phone with mobile data ([ADR 0002](0002-inertia-monolith.md)). The address itself is precise in the Philippines: street, barangay, city and province, from the PSA's list ([ADR 0010](0010-psgc-places-data.md)).

## Options

1. **An embedded map** (Google Maps JavaScript API, or Leaflet with OpenStreetMap tiles): a map script and tiles on the listing page, coordinates for every property, an API key and billing account (Google) or a tile server that allows the traffic (OpenStreetMap's own tiles don't, beyond light use), and a CSP that allows the provider's scripts and images.
2. **A static map image** per property: no script, but still coordinates, a provider, and an image on the slowest page.
3. **No map**: the full address, and an "Open in Maps" link to a Google Maps search of the address text, opened in a new tab.

## Decision

Option 3. `lat` and `lng` were dropped in the UI review after slice 4.2; the listing page (5.1) shows the address and the link (`https://www.google.com/maps/search/?api=1&query=…`).

## Consequences

- Good: no map script, tiles, API key or third-party origin in the CSP; nothing on the listing page that costs Lighthouse points (it scores 90 or more on a simulated slow-4G phone, [perf/lighthouse.md](../perf/lighthouse.md)).
- Good: no coordinates to collect, validate or get wrong; the owner chooses a barangay from a list instead.
- Good: the phone's own maps app opens the search, with directions from where the renter is.
- Bad: no "near me" search and no results on a map. Both are in the backlog.
- Bad: the link searches the address text, so a street the map provider doesn't know lands on the barangay.
- Follow-up: if a map comes back, place the pin by geocoding the address or with a "drop a pin" picker, never typed coordinates (backlog).
