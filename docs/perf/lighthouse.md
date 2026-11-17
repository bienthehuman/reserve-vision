# Lighthouse (mobile): scores, fixes, and what isn't measured

Slice 7.2. The source for the README's numbers (7.3). Everything here is **Lighthouse 13.6 through `@lhci/cli`, mobile preset** (Moto G Power-class emulation, simulated slow 4G, Lighthouse's default), **3 runs per page, the median run reported**, against the **production stack** (`compose.prod.yaml` through `deploy.sh`, demo data seeded, Caddy on plain HTTP). The dev stack's Vite server would give meaningless numbers. Config: [lighthouserc.cjs](../../lighthouserc.cjs), bar: [lighthouse-thresholds.cjs](../../lighthouse-thresholds.cjs), table: [bin/lighthouse-summary.mjs](../../bin/lighthouse-summary.mjs). CI: the `Lighthouse (mobile)` job of the Production workflow, which fails the PR below the assertions and puts this table in its summary.

Numbers move between runs (the same code gave 95 to 98 on the home page, with LCP from 2.4 to 2.9 s, and 90 to 91 on the listing page over five sets of three), so quote ranges in prose and use the CI summary for a given commit. The table below is the last local set on the final code.

## Assertions

| Category | Pages | Bar |
| --- | --- | --- |
| Accessibility | all four | 100 |
| Best Practices | all four | ≥ 95 |
| SEO | home, search, listing | ≥ 95 (`/login` is `noindex` on purpose: its SEO score is about 54 by design and isn't asserted) |
| CLS | all four | ≤ 0.1 |
| Performance | all four | ≥ **90** |

**How the Performance bar was set.** Measured first: the baseline had the search page at 68. After the fixes below the lowest page is the last-bed listing at 90 to 91 (home 95 to 98, search 96 to 97, login 98 to 99). The bar is the lowest page rounded down to a multiple of 5, and not below 85, so 90. The listing page has about one point of headroom: a CI runner slower than this machine may flap it, and that would be the signal to ship the mid-size photo variant from the backlog rather than lower the bar.

## What is not measured (said honestly)

- **HTTPS.** CI serves plain HTTP on `localhost`, so the `is-on-https` and `redirects-http` audits can only fail for a reason a real deployment doesn't have. They are **skipped** (`skipAudits` in `lighthouserc.cjs`), and the job summary says so. Best Practices is therefore measured without them; with them it would lose the HTTPS items until the first TLS run (backlog: run Lighthouse once against a deployment with TLS). HTTP/2 and HTTP/3 (Caddy on TLS) aren't measured either.
- **Slow CPUs and real networks.** Lighthouse simulates both from a fast run; a real Moto G on a real 4G varies.
- **Logged-in pages.** The landlord and renter pages are server-rendered too, but they sit behind a login and are not entry pages for a first visit; they're covered by the accessibility sweep, not by Lighthouse.

## Before and after

Baseline = `main` at 7.1. After = this branch, same stack, same machine.

| Page | | Perf | A11y | Best practices | SEO | LCP | CLS | TBT | Transfer |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `/` | before | 96 | 100 | 100 | 92 | 2.75 s | 0.000 | 62 ms | 321 kB |
| | after | 95 | 100 | 100 | **100** | 2.87 s | 0.000 | 55 ms | 322 kB |
| `/listings?city=manila` | before | **68** | 100 | 100 | 92 | 3.38 s | **0.662** | 99 ms | 376 kB |
| | after | **96** | 100 | 100 | **100** | **2.60 s** | **0.000** | 84 ms | 378 kB |
| `/listings/manila/santos-bedspace-house` | before | 90 | 100 | 100 | 100 | 3.51 s | 0.000 | 117 ms | 434 kB |
| | after | 90 | 100 | 100 | 100 | 3.51 s | 0.000 | 109 ms | 435 kB |
| `/login` | before | 99 | 100 | 100 | 54 (noindex) | 0.63 s | 0.000 | 102 ms | 215 kB |
| | after | 99 | 100 | 100 | 54 (noindex) | 0.64 s | 0.000 | 111 ms | 216 kB |

**On the CI runner** ([run 44028626664](https://github.com/bienthehuman/reserve-vision/actions/runs/44028626664), the `Lighthouse (mobile)` job, same commit): home 95, search 96, last-bed listing **92**, login 100 (Accessibility 100 and Best Practices 100 everywhere, SEO 100 on the three public pages, CLS 0.000, LCP 2.91 s, 2.71 s, 3.40 s and 0.61 s). The listing page has two points of headroom over the bar there.

## Each fix

| Fix | Why (the audit) | Measured effect |
| --- | --- | --- |
| **The search filters render collapsed on the server** (`FilterSheet`: a visually hidden checkbox and a label that looks like the "Filters" button, below `lg`) | `layout-shifts`: the whole filter form, thousands of pixels of it, was in the server HTML and became a button when the page hydrated, so the results jumped up | Search CLS **0.662 → 0.000**, Performance **68 → 96** |
| **The first card's cover loads eagerly with `fetchpriority=high`** (`ListingCard priority`, the first card on home and search) | `lcp-discovery-insight`: the LCP image was `loading="lazy"`, so the browser found it late | Search LCP 3.38 s → 2.6 s (together with the CLS fix, which stops the late-arriving results from resetting it). Home: 2.75 s before, 2.4 to 2.9 s after over four sets of runs, and Performance 95 to 98 against 96 before, so **no measured gain on home**, within the run-to-run noise; kept because the LCP image should never be lazy |
| **A meta description on home and search** (`Head` with a `head-key`; the pitch on home) | `meta-description` | SEO **92 → 100** on both |
| **Built assets cached for a year, immutable** (Caddy `handle /build/assets/*`; `bin/smoke-production` checks it) | `cache-insight`: `app.js`, `app.css` and the heading font had no cache lifetime, 196 to 231 kB re-downloaded on every visit | Repeat visits fetch none of the 181 kB of hashed assets (a first visit is unchanged, and Lighthouse measures a cold load, so no score moves) |
| **Entry chunk: analysed, nothing to split** | `unused-javascript` | 113.21 kB gzipped, of which react-dom is 208 kB raw and Inertia with `@inertiajs/core`, es-toolkit and laravel-precognition about 138 kB raw: the framework floor. Icons, Downshift, date helpers and every page are already separate chunks. Held with a budget instead (`bin/check-js-budget.mjs`: entry 118.9 kB, listing page 23.7 kB, both +5 % over today) |
| **Heading font** | `font-display`, preload | Already right at 7.1 (`font-display: swap` from `@fontsource-variable`, one `<link rel="preload" as="font" crossorigin>` of the Latin woff2): no change |

Left on the table, with the reason:

- **The listing cover is the 1600 px file** (153 kB): a phone's `srcset` choice is the 480 px thumb or the 1600 px full, and 480 is too small for a 412 px screen at 1.75x. Lighthouse's `image-delivery-insight` puts the saving at about 120 kB, which would take the listing page's LCP from 3.5 s towards 3 s. It needs a third, mid-size variant (new file per photo, a backfill for existing photos, changes in the processor and the actions that write and delete files), which is not a one-line fix: **backlog**.
- **The render-blocking `app.css`** (35 kB gzipped, within its budget): `render-blocking-insight` says 150 to 290 ms. Inlining critical CSS is a build-pipeline change for a gain under one score point.
- **`unused-css-rules` / `unused-javascript`** are diagnostics (not scored in Lighthouse 13): one stylesheet and one framework chunk serve every page by design (ADR 0002).

## Reproducing

See [CLAUDE.md](../../CLAUDE.md#performance-and-accessibility-slice-72).
