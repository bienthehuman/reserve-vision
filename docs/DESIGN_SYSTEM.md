# Design system

The source of truth for how ReserveVision looks. `resources/scss/_tokens.scss` and `_theme.scss` must match this file, and a dev-only `/styleguide` page (slice 1.1) renders every token and component listed here. To preview the palette before any code exists, open [design/preview.html](design/preview.html).

## Principles

1. **Readable before JavaScript.** Every public page is complete, styled HTML from SSR. JavaScript adds convenience only.
2. **Mobile first, mobile data.** Design at 360 px wide first. Keep CSS small: only the Bootstrap partials we use, one web font for headings.
3. **Trust over flash.** Renters send money to strangers based on these pages. Calm colors, clear prices and fees, plain status words.
4. **Not stock Bootstrap.** Custom palette, heading font, radii and shadows, all set through Sass variables. Never override with `!important`.
5. **Color is never the only signal.** Every status has a word and an icon as well as a color.
6. **Content sits on a surface.** The page is tinted; tables, forms and details are white cards on it. Two layers, never bare content on the page and never a card inside a card.
7. **One of each.** One control height, one control radius, one primary action per view. Things that sit side by side are the same size.
8. **Plain words.** Written for a landlord who has never used a web app: no "slug", "enforce", "later version" or coordinates. If a field needs a paragraph to explain, it shouldn't be a field.

## Color

Light mode only in v1. Dark mode is in the backlog; tokens are named by role, not hue, so it can be added later without renaming. Every text pair below meets **WCAG AA (4.5:1)** and every UI-boundary pair meets **3:1**. The ratios were computed, not eyeballed.

### Brand and neutrals

| Token | Hex | Use | Contrast |
| --- | --- | --- | --- |
| `primary` | `#5B3E96` ube | Primary buttons, links, active nav, focus ring, **prices** | 8.2 on white, 7.7 on `bg` |
| `primary-hover` | `#47307A` | Hover and pressed state | 10.7 on white |
| `primary-subtle` | `#F1ECF8` | Hovered menu items and options, icon tiles, "Living here" badge | `primary` text on it: 7.0 |
| `primary-border` | `#C9BCE3` | The line of a secondary button, a hovered card | decorative (the label carries the button) |
| `mango` | `#F7C948` | Brand highlight: "Last bed" tags, the brand mark, and the `warning` background. **Background only**, always with `ink` text (10.5). Never text or an icon on white (1.6) | — |
| `ink` | `#211D2B` | Body text, headings | 15.1 on `bg`, 16.5 on white |
| `muted` | `#5E596B` | Secondary text, captions, labels in a details list, table headers | 6.2 on `bg`, 6.7 on white, 6.4 on `surface-muted` |
| `border-strong` | `#827C90` | Input, select and checkbox borders | 4.0 on white, 3.7 on `bg` (UI 3:1) |
| `border` | `#E2DEEA` | Card and divider lines (decorative only) | — |
| `surface` | `#FFFFFF` | The layer content sits on: cards, tables, forms, menus, modals, inputs, the header | — |
| `surface-muted` | `#FAF9FC` | Inside a surface: table header rows, hovered rows, a form's action bar, read-only fields | — |
| `bg` | `#F6F4FA` | Page background, one step darker than a surface | — |

**Neutrals lean towards ube.** Ink, muted, the borders and the page all sit near hue 260°, so text, lines and backgrounds belong to the brand color instead of fighting it (the first version mixed a teal-grey ink with a warm page). Mango is the one warm color, which is why it stands out.

**Why ube and mango.** The palette has to stand apart from the rest of the portfolio. Hilom and PesoTrack both use teal (hue 175°), and the portfolio site uses an orange accent (15°). Ube purple (260°) is far from both, isn't Bootstrap blue or the generic SaaS indigo, and with mango yellow it reads as distinctly Filipino. Prices use bold `primary`, so there's one strong brand color and one highlight, nothing more.

**Rule for future palettes:** before choosing a primary, check the hues already used in the other portfolio projects.

### Semantic

| Token | Hex | Subtle background | Text on subtle | Use |
| --- | --- | --- | --- | --- |
| `success` | `#1E7B45` | `#E7F4EC` | 4.7 | Confirmed, available, saved |
| `warning` | `#F7C948` = `mango` (as a background, with `ink` text: 10.5) | `#FEF6DA` | `ink` 15.2 | Holds, deadlines, countdowns, scarcity ("Last bed") |
| `danger` | `#B42318` | `#FDECEA` | 5.8 | Declined, errors, destructive buttons |
| `info` | `#1D5FA8` | `#E8F0FA` | 5.6 | Awaiting review, neutral notices |
| `secondary` | `#5E596B` (= `muted`) | `#EFEDF3` | 5.8 | Ended, expired, cancelled, archived |

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
| Radius | `sm 0.375rem` menu items, options, chips · `base 0.5rem` **every control**: buttons of both sizes, inputs, dropdowns · `lg 0.75rem` cards, tables, menus, alerts, photos · `xl 1rem` modals, sheets · `pill` badges, the account button | A small button has the same radius as a full-size one |
| Shadow | `sm 0 1px 2px rgb(33 29 43 / .05)` cards · `base 0 4px 16px rgb(33 29 43 / .08)` hover, dropdown lists, sticky bars · `lg 0 12px 32px rgb(33 29 43 / .14)` menus, modals, sheets | Shadows tinted with `ink`, never pure black |
| Control height | `2.5rem` (40 px) for buttons, inputs and dropdowns alike; `2rem` (32 px) for small buttons | Set as `min-height` from `--rv-control-height`, so a button next to a field always lines up. Button text is `0.9375rem`/600, so the label fits the button instead of a big box around small text |
| Touch target | 44 px on touch screens | `@media (pointer: coarse)` raises the control height to `2.75rem` (small: `2.5rem`). With a mouse, 40 and 32 px are well over WCAG 2.2's 24 px minimum |
| Container | max 1140 px; 16 px side gutter on phones | |
| Breakpoints | Bootstrap defaults (`sm 576`, `md 768`, `lg 992`, `xl 1200`) | Design at 360 first, then `md` and `lg` |
| Motion | 150 ms ease-out for hover/focus; none for layout | Respect `prefers-reduced-motion` |

## Focus and accessibility

- **Focus ring:** `outline: 3px solid primary; outline-offset: 2px` on every interactive element via `:focus-visible`. It replaces Bootstrap's translucent box-shadow ring, which fails 3:1 on light backgrounds.
- **Text fields and dropdowns** (`.form-control`, `.form-select`) don't get the ring. On focus their border turns `primary` and doubles to 2 px (a 1 px inset shadow, so nothing moves): 8.2:1 on white. Invalid fields do the same in `danger`. Hover darkens the border to `ink`. Checkboxes and radios keep the ring, because a checked one is already filled `primary`.
- Every form field has a visible `<label>`; placeholders are examples, never labels.
- Errors: `danger` text + icon below the field, linked with `aria-describedby`; a summary at the top of long forms.
- Countdown timers (`HoldCountdown`) update the visible text every minute but announce only at 1h and 10 min remaining (`aria-live="polite"`).
- Images: listing photos have meaningful `alt` text ("Bed area of Room 2, Sampaguita Boarding House"); decorative images use `alt=""`.
- axe runs on every page in CI (zero violations), as set in [ARCHITECTURE.md](ARCHITECTURE.md#production-constraints-from-the-first-slice).

## Components

Use React-Bootstrap first; build a custom component only when the table says so. Custom components live in `resources/js/Components/` and appear on `/styleguide`.

| Need | Component | Notes |
| --- | --- | --- |
| Buttons | `Button` | `primary` (filled ube): the one main action of a view. `outline-primary` (white, soft ube line): every other action. `outline-danger`: a destructive action that opens a confirmation; `danger` (filled): the confirming button in that dialog. No `secondary` or `link` buttons. Sizes: default next to fields and in page headers; `sm` only in rows and cards, and only next to other small buttons. An icon goes before the label (after it for "Next") |
| Links | `a`, Inertia `Link` | In a sentence a link keeps an underline, drawn thin and soft until hovered (color alone isn't a signal). A link that stands alone has none: `.link-plain` (ink, 600; names in a table, card titles), `.link-action` (ube, 600; "Forgot your password?", an email address), `.back-link` (muted, small, with an arrow; "Back to properties") |
| Forms | `Form`, `FloatingLabel` is **not** used | On a card as wide as the page, in titled sections (`.form-section`): from `lg` a section's title and intro are on the left and its fields (`.form-section__fields`) on the right, so every section's fields line up and the card has no empty half. The buttons are in an action bar (`.form-actions`), **on the right, with the one that saves or deletes last**: Cancel, then Save. The same goes for any row of buttons under a form or on a card (`.button-row`), and for the confirmation dialogs. Labels above fields; `Form.Text` for help. Long forms pair fields side by side from `sm`. A value the form shows but nobody types is a read-only field on `surface-muted` |
| Dropdowns | `Form.Select` (native) | A native `<select>`: best on phones and with screen readers. The closed field matches the inputs (`primary` chevron, the control radius, border, hover and focus). The list that opens is styled where the browser allows it (`appearance: base-select`, Chromium today): a rounded card with the dropdown shadow, options that highlight in `primary-subtle`, and a tick on the chosen one. Other browsers show their own list. A long list with natural groups uses `<optgroup>` (`SelectField` options with a `group`: Manila's barangays by district). **Dependent dropdowns** (the address, slice 4.4): a dropdown that needs another one is disabled and its empty option says what it waits for ("Choose a province first."), so the form explains itself in any field order; while its list loads it says "Loading…"; a list that fails to load says so under the field with a "Try again" button (`.place-error`), never an empty list. Changing a choice clears the ones that depend on it |
| Navigation | custom header | White header: the brand on the left; on the right Log in / Sign up, or the **account menu**. Landlord pages add the tab row under it (Dashboard, Reservations, Properties, Members), which scrolls sideways on a phone. The header has no Dashboard button: the tabs have it |
| Dialogs | `Modal` | Confirmations of destructive or irreversible actions only |
| Feedback | `FlashToast`, `FlashError` (`Components/FlashMessages`), `Alert` | **A confirmation is a toast** ("Saved Room 1.", "We've sent an invitation to…"): fixed under the header, top right from `md` and full width on a phone, so it never pushes the page down or sits above a menu. It closes itself after 6 seconds, waits while the pointer or keyboard focus is on it, and has a close button. `role="status"`, no fade. **Anything the user must read is an alert that stays**: a broken rule is shown at the top of the content, below any menu, scrolls into view and takes focus when it appears, and stays until dismissed. Never put information the user needs later (a link, a code) in a toast |
| Tables | `Table` in `Card.table-card` | On a card: a quiet header row (`surface-muted`, small muted labels), hairlines, a hover. `.stack-table` turns each row into its own card below `md` (one table in the DOM; each cell shows its column name from `data-label`) |
| **PageHeader** | custom | The top of every page inside the app: back link, an optional quiet line above the title, the `h1`, a subtitle or status under it, and the page's actions on the right (under the title on a phone) |
| **UserMenu** | custom | The header's account menu: the user's first name with their initial is a button; it opens a panel with the full name, the role and Log out (for a landlord away from the landlord pages, also Dashboard). A disclosure (`aria-expanded`), closed by Escape, a click outside or focus leaving |
| **Pager** | custom | "Page 3 of 22" on the left; Previous and Next **side by side** on the right, so paging never means crossing the screen. At either end the button stays, disabled, so the other doesn't move |
| **Details list** | `dl.details` | Label and value pairs on a card: the label small and muted, the value in ink |
| **ListingCard** | custom | Photo (4:3, lazy), name, barangay + city, `PriceTag`, gender-policy and kind chips, "N spaces free" |
| **PriceTag** | custom | `₱6,500 / month`, always from integer centavos via `formatPeso()` |
| **StatusBadge** | custom | The mapping table above |
| **TextField** | custom on `Form.Group` | Label above, help (`Form.Text`) and error below, both in `aria-describedby`; error is `danger` text with an icon. The input id is `field-<name>`. `type="password"` adds a 44 px show/hide button inside the field (eye icon, `aria-label="Show password"`, `aria-pressed`) |
| **ErrorSummary** | custom on `Alert` | "Please fix these N things:" with one link per error to its field; takes focus after a failed submit (`useFocusOnError`) |
| **SubmitButton** | custom on `Button` | Full width on auth forms; while the request runs it is disabled and shows a spinner and a pending label ("Logging in…") |
| **HoldCountdown** | custom | Remaining hold time; server sends `hold_expires_at`, so it's correct without JS (static "until 3:40 PM") |
| **FilterSheet** | custom on `Offcanvas placement="bottom"` | Search filters on phones; a sidebar from `lg` |
| **StickyReserveBar** | custom | On a listing page on phones: price + "Reserve" button fixed at the bottom |
| **EmptyState** | custom | On a card: an icon in a `primary-subtle` circle, one sentence, at most one action |
| **ProofViewer** | custom | Shows an image or PDF from a signed URL; zoom on tap |

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

Settings in `_theme.scss`: cards, list groups and modals are `surface` on the tinted page (`$card-bg`), tables are transparent so they take their card's color, `$enable-rfs: false`, `$enable-shadows: false`, `$enable-negative-margins: false`, `$enable-dark-mode: false` (no dark styles emitted in v1), and `$theme-colors` trimmed to `primary, success, warning, danger, info, secondary, light, dark`. The `@import` syntax matches what Bootstrap 5.3's Sass still requires; silence Dart Sass deprecation warnings for vendor files only.

**CSS budget:** ≤ 35 KB gzipped for `app.css`, checked in CI since slice 1.2 (`bin/check-css-budget.mjs` in the Quality workflow; the size is in its job summary).

## Voice

- Plain words, second person: "Your hold ends in 23 hours", not "Reservation status: HELD".
- Money always with the peso sign and a period: `₱1,500.00` for fees, `₱6,500` for monthly rates.
- Dates as `Mon, 1 Jun 2026`; times as `3:40 PM`, Manila time, labelled "Philippine time" where it matters.
- An address is written street address (when there is one), barangay, city, province: "12 Mabini Street, Loyola Heights, Quezon City, Metro Manila". A Manila barangay comes with its district: "Barangay 395, Sampaloc, Manila, Metro Manila". Place names are what people write, not the PSGC's titles: "Makati", not "City of Makati"; "Cebu City", where the bare name is also a province.
- Say what happens, not how the system works: "Changing it later only affects new reservations", not "A reservation keeps the fee it was made with". "Restore", not "Unarchive". "Listing link", not "web address" or "slug". Nothing is promised for "a later version": say "soon", or leave it out.
- English UI in v1. All strings go through Laravel's translation helpers so Filipino can be added later (backlog).
