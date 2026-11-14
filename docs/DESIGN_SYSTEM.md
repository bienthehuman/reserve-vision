# Design system

The source of truth for how ReserveVision looks. `resources/scss/_tokens.scss` and `_theme.scss` must match this file, and a dev-only `/styleguide` page (slice 1.1) renders every token and component listed here. To preview the palette before any code exists, open [design/preview.html](design/preview.html).

## Principles

1. **Readable before JavaScript.** Every public page is complete, styled HTML from SSR. JavaScript adds convenience only.
2. **Mobile first, mobile data.** Design at 360 px wide first. Keep CSS small: only the Bootstrap partials we use, one web font for headings.
3. **Trust over flash.** Renters send money to strangers based on these pages. Calm colors, clear prices and fees, plain status words.
4. **Not stock Bootstrap.** Custom palette, heading font, radii and shadows, all set through Sass variables. Never override with `!important`.
5. **Color is never the only signal.** Every status has a word and an icon as well as a color.

## Color

Light mode only in v1. Dark mode is in the backlog; tokens are named by role, not hue, so it can be added later without renaming. Every text pair below meets **WCAG AA (4.5:1)** and every UI-boundary pair meets **3:1**. The ratios were computed, not eyeballed.

### Brand and neutrals

| Token | Hex | Use | Contrast |
| --- | --- | --- | --- |
| `primary` | `#5B3E96` ube | Primary buttons, links, active nav, focus ring, **prices** | 8.2 on white, 7.7 on `bg` |
| `primary-hover` | `#47307A` | Hover and pressed state | 10.7 on white |
| `primary-subtle` | `#F1ECF8` | Selected filter chips, highlighted rows, "Living here" badge | `primary` text on it: 7.0 |
| `mango` | `#F7C948` | Brand highlight: "Last bed" tags, the brand mark, and the `warning` background. **Background only**, always with `ink` text (9.4). Never text or an icon on white (1.6) | — |
| `ink` | `#1F2A2E` | Body text, headings, footer background | 13.9 on `bg` |
| `muted` | `#5B6770` | Secondary text, captions, metadata | 5.5 on `bg` |
| `border-strong` | `#7D878F` | Input, select and checkbox borders | 3.5 on `bg` (UI 3:1) |
| `border` | `#D9DEE1` | Card and divider lines (decorative only) | — |
| `surface` | `#FFFFFF` | Cards, sheets, modals, inputs | — |
| `bg` | `#FAF8F5` warm off-white | Page background | — |

**Why ube and mango.** The palette has to stand apart from the rest of the portfolio. Hilom and PesoTrack both use teal (hue 175°), and the portfolio site uses an orange accent (15°). Ube purple (260°) is far from both, isn't Bootstrap blue or the generic SaaS indigo, and with mango yellow it reads as distinctly Filipino. Prices use bold `primary`, so there's one strong brand color and one highlight, nothing more.

**Rule for future palettes:** before choosing a primary, check the hues already used in the other portfolio projects.

### Semantic

| Token | Hex | Subtle background | Text on subtle | Use |
| --- | --- | --- | --- | --- |
| `success` | `#1E7B45` | `#E7F4EC` | 4.7 | Confirmed, available, saved |
| `warning` | `#F7C948` = `mango` (as a background, with `ink` text: 9.4) | `#FEF6DA` | `ink` 13.6 | Holds, deadlines, countdowns, scarcity ("Last bed") |
| `danger` | `#B42318` | `#FDECEA` | 5.8 | Declined, errors, destructive buttons |
| `info` | `#1D5FA8` | `#E8F0FA` | 5.6 | Awaiting review, neutral notices |
| `secondary` | `#5B6770` (= `muted`) | `#EEF0F1` | 5.1 | Ended, expired, cancelled, archived |

`warning`/`mango` is too light for text or icons on white. It is only ever a background, and icons on it are `ink`. Sharing one yellow for holds and scarcity is deliberate: both mean "act soon"; the label and icon tell them apart.

### Reservation status badges

One `StatusBadge` component owns this mapping; no page picks status colors itself.

| Status | Label shown | Color | Icon |
| --- | --- | --- | --- |
| `held` | On hold · 23h left | warning subtle | hourglass |
| `proof_uploaded` | Awaiting review | info subtle | file-earmark-check |
| `confirmed` | Confirmed | success subtle | check-circle |
| `active` | Living here | primary subtle | house-door |
| `expired` | Hold expired | secondary subtle | clock-history |
| `declined` | Declined | danger subtle | x-circle |
| `cancelled` | Cancelled | secondary subtle | slash-circle |
| `ended` | Stay ended | secondary subtle | box-arrow-right |

Viewing requests use the same component: requested → info, accepted → success, declined → danger, done or cancelled → secondary.

## Typography

| Role | Font | Notes |
| --- | --- | --- |
| Headings (h1–h4), brand | **Plus Jakarta Sans**, variable, weights 600–700 | Self-hosted WOFF2, Latin subset, `font-display: swap`, preloaded on public pages. OFL licence |
| Body, UI, prices, numbers | System stack: `system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif` | Zero download. System fonts render the peso sign ₱ reliably, so prices use the body font. Prices use `font-variant-numeric: tabular-nums` |

| Step | Size (mobile → ≥ md) | Weight | Line height | Use |
| --- | --- | --- | --- | --- |
| `display` | 2rem → 2.5rem | 700 | 1.15 | Home hero only |
| `h1` | 1.625rem → 2rem | 700 | 1.2 | Page title, listing name |
| `h2` | 1.375rem → 1.5rem | 700 | 1.25 | Section |
| `h3` | 1.125rem → 1.25rem | 600 | 1.3 | Card title, room name |
| `body` | 1rem (16 px) | 400 | 1.5 | Default. Never smaller for form inputs (prevents iOS zoom) |
| `small` | 0.875rem | 400 | 1.45 | Metadata, captions |
| `price` | 1.25rem | 700, `primary` | 1.2 | `₱6,500 / month`, the "/ month" part in `small` + `muted` |

Bootstrap's responsive font sizes (`$enable-rfs`) are turned off; the two-step sizes above are explicit.

## Space, shape and depth

| Token | Value | Notes |
| --- | --- | --- |
| Spacer | `1rem` with Bootstrap's 0–5 scale | Plus `6: 4rem` for section gaps |
| Radius | `sm 0.375rem` inputs, chips · `base 0.625rem` buttons, cards · `lg 1rem` sheets, modals, photos · `pill` badges | Rounder than stock Bootstrap |
| Shadow | `sm 0 1px 2px rgb(31 42 46 / .06)` cards · `base 0 4px 16px rgb(31 42 46 / .08)` hover, sticky bars · `lg 0 12px 32px rgb(31 42 46 / .12)` modals, sheets | Shadows tinted with `ink`, never pure black |
| Touch target | ≥ 44 × 44 px | Buttons default to `0.75rem 1.25rem` padding |
| Container | max 1140 px; 16 px side gutter on phones | |
| Breakpoints | Bootstrap defaults (`sm 576`, `md 768`, `lg 992`, `xl 1200`) | Design at 360 first, then `md` and `lg` |
| Motion | 150 ms ease-out for hover/focus; none for layout | Respect `prefers-reduced-motion` |

## Focus and accessibility

- **Focus ring:** `outline: 3px solid primary; outline-offset: 2px` on every interactive element via `:focus-visible`. It replaces Bootstrap's translucent box-shadow ring, which fails 3:1 on light backgrounds.
- Every form field has a visible `<label>`; placeholders are examples, never labels.
- Errors: `danger` text + icon below the field, linked with `aria-describedby`; a summary at the top of long forms.
- Countdown timers (`HoldCountdown`) update the visible text every minute but announce only at 1h and 10 min remaining (`aria-live="polite"`).
- Images: listing photos have meaningful `alt` text ("Bed area of Room 2, Sampaguita Boarding House"); decorative images use `alt=""`.
- axe runs on every page in CI (zero violations), as set in [ARCHITECTURE.md](ARCHITECTURE.md#production-constraints-from-the-first-slice).

## Components

Use React-Bootstrap first; build a custom component only when the table says so. Custom components live in `resources/js/Components/` and appear on `/styleguide`.

| Need | Component | Notes |
| --- | --- | --- |
| Buttons | `Button` | Variants used: `primary`, `outline-primary`, `danger`, `link`. No `secondary` buttons; use `outline-primary` |
| Forms | `Form`, `FloatingLabel` is **not** used | Labels above fields; `Form.Text` for help |
| Navigation | `Navbar` + `Offcanvas` on mobile | Sticky top; bottom action bar on listing pages (see below) |
| Dialogs | `Modal` | Confirmations of destructive or irreversible actions only |
| Feedback | `Alert`, `Toast` | Toast for "saved"; Alert for anything the user must read |
| Tables | `Table` responsive | Landlord dashboard; collapses into stacked cards below `md` |
| **ListingCard** | custom | Photo (4:3, lazy), name, barangay + city, `PriceTag`, gender-policy and kind chips, "N spaces free" |
| **PriceTag** | custom | `₱6,500 / month`, always from integer centavos via `formatPeso()` |
| **StatusBadge** | custom | The mapping table above |
| **HoldCountdown** | custom | Remaining hold time; server sends `hold_expires_at`, so it's correct without JS (static "until 3:40 PM") |
| **FilterSheet** | custom on `Offcanvas placement="bottom"` | Search filters on phones; a sidebar from `lg` |
| **StickyReserveBar** | custom | On a listing page on phones: price + "Reserve" button fixed at the bottom |
| **EmptyState** | custom | Icon, one sentence, one action |
| **ProofViewer** | custom | Shows an image or PDF from a signed URL; zoom on tap |
| **MapPin** | custom (Leaflet) | One pin, loaded when scrolled into view; static address text always present |

**Icons:** Bootstrap Icons as individual SVG imports (`react-bootstrap-icons`), tree-shaken, `aria-hidden` unless they stand alone.

## Bootstrap Sass setup

```scss
// resources/scss/app.scss — order matters
@import "tokens";                 // the colors, fonts, radii above as Sass vars
@import "bootstrap/scss/functions";
@import "theme";                  // maps tokens onto Bootstrap variables ($primary, $border-radius, $font-family-sans-serif…)
@import "bootstrap/scss/variables";
@import "bootstrap/scss/variables-dark"; // required by maps even with dark mode off
@import "bootstrap/scss/maps";
@import "bootstrap/scss/mixins";
@import "bootstrap/scss/utilities";
// Only what we use:
@import "bootstrap/scss/root", "bootstrap/scss/reboot", "bootstrap/scss/type", "bootstrap/scss/images",
        "bootstrap/scss/containers", "bootstrap/scss/grid", "bootstrap/scss/forms", "bootstrap/scss/buttons",
        "bootstrap/scss/transitions", "bootstrap/scss/dropdown", "bootstrap/scss/nav", "bootstrap/scss/navbar",
        "bootstrap/scss/card", "bootstrap/scss/badge", "bootstrap/scss/alert", "bootstrap/scss/list-group",
        "bootstrap/scss/close", "bootstrap/scss/toasts", "bootstrap/scss/modal", "bootstrap/scss/offcanvas",
        "bootstrap/scss/tables", "bootstrap/scss/spinners", "bootstrap/scss/helpers";
@import "bootstrap/scss/utilities/api";
@import "components";             // custom component styles
```

Settings in `_theme.scss`: `$enable-rfs: false`, `$enable-shadows: false`, `$enable-negative-margins: false`, `$enable-dark-mode: false` (no dark styles emitted in v1), and `$theme-colors` trimmed to `primary, success, warning, danger, info, secondary, light, dark`. The `@import` syntax matches what Bootstrap 5.3's Sass still requires; silence Dart Sass deprecation warnings for vendor files only.

**CSS budget:** ≤ 35 KB gzipped for `app.css`, checked in CI from slice 2.

## Voice

- Plain words, second person: "Your hold ends in 23 hours", not "Reservation status: HELD".
- Money always with the peso sign and a period: `₱1,500.00` for fees, `₱6,500` for monthly rates.
- Dates as `Mon, 1 Jun 2026`; times as `3:40 PM`, Manila time, and labelled as such in emails.
- English UI in v1. All strings go through Laravel's translation helpers so Filipino can be added later (backlog).
