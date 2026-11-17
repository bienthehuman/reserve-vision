# Accessibility: the sweep, the keyboard check, and what they found

Slice 7.2. Rules: [DESIGN_SYSTEM.md](../DESIGN_SYSTEM.md) (principles 1 and 2, "Accessibility"). Target: WCAG 2.2 AA, zero axe violations (tags `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa`, `best-practice`), Lighthouse Accessibility 100 on the public pages ([lighthouse.md](lighthouse.md)).

## The sweep: axe on every page, provably

[e2e/a11y-sweep.spec.ts](../../e2e/a11y-sweep.spec.ts), run by `npm run e2e` at both viewports (`phone-360`, `desktop-1280`), on the dev stack and on the production stack in CI.

**Why "every page" is true.** The page list is not written by hand:

1. `php artisan app:page-routes --write` lists every `GET` route with its access class (from the middleware) into [e2e/fixtures/page-routes.json](../../e2e/fixtures/page-routes.json) (39 routes). `tests/Feature/Docs/PageRoutesFixtureTest.php` fails when the file is stale.
2. The spec's first test fails for any route that is in neither `PAGES` (a page, with the actors that open it) nor `NOT_PAGES` (a file, a redirect or JSON, with the reason). It also checks the actors fit the middleware (a `landlord` page needs the owner **and** the caretaker; a `renter` page a renter).
3. Each actor's session records the routes it really visited, and its test fails if they differ from `PAGES`. So a mapped page nobody visits fails too.

**Negative controls (run in 7.2).** A temporary `GET /about` route: without regenerating the fixture, the Pest test fails ("`e2e/fixtures/page-routes.json` is stale"); after regenerating it, the sweep fails with `GET route(s) with no entry in e2e/a11y-sweep.spec.ts: about`. And an `<img>` without `alt` on the home page: the sweep fails with `axe image-alt (critical) on guest home /`.

**Coverage** (one run per viewport; the numbers are printed by the last test of the sweep):

| | Phone 360 | Desktop 1280 |
| --- | ---: | ---: |
| GET routes | 39 (26 pages, 13 not pages) | same |
| Route × actor pairs | 44 | 44 |
| Page visits (route × actor × state) | 89 | 89 |
| Dialogs opened and swept | 17 (the filter sheet is one on a phone) | 12 |
| Extra states (validation errors, dialogs' own errors) | 14 | 14 |

Against the production stack (what CI runs; `/styleguide` and `/dev/mail` don't exist there): 42 pairs, 86 page visits, 15 dialogs and 12 extra states on the phone, 0 problems, run locally on the final build.

Actors: guest, renter (logged in as the renters of the demo reservations, one per status), unverified renter (registers a new account), owner, caretaker. 26 page routes: 10 for the guest (2 only against the dev stack), 15 for the owner, 12 for the caretaker, 6 for a renter, 1 unverified.

**States covered**: the hold page in every reservation status (the demo data has a live hold, held-but-expired and `expired`, proof uploaded, confirmed, active, ended, declined, cancelled), the landlord decision panel for the same eight statuses, a reservation list with a filter and one with no rows (empty state), the viewing lists and a viewing per status, the search page with results and with no matches, every property of the demo owner, the 403 page a caretaker gets on the four owner-only forms and on the members page, the 404 page of a link that can't be used, **validation errors** on login, register, forgot password, new property, new room, the invite form and the proof form, and **every dialog** (`aria-haspopup="dialog"`: the confirm dialogs, the filter sheet), a dialog with a required reason also confirmed empty. The public pages are also judged as the server sends them with JavaScript off (the search filters are a different, collapsed element then).

**Checked on every visit besides axe**: the HTTP status is the expected one, exactly one `h1`, a non-empty document title that no other route shares, and no console or CSP errors.

**Not covered** (backlog): the invitation page's usable states (register, log in, join): a live invitation link only exists inside the phase 4 demo, so the sweep covers the neutral 404 of an unusable one. Photo upload progress states are in `e2e/photos.spec.ts`'s axe runs, not the sweep.

## What it found, and the fix

| Finding | Where | Fix |
| --- | --- | --- |
| The **403/404/419/429/500/503 pages were unstyled in a real browser**: Laravel's error layout has an inline `<style>` with no nonce, which our CSP blocks (a console error on every error page; axe didn't notice, since the unstyled page is still valid HTML) | The caretaker's 403 pages | `resources/views/errors/minimal.blade.php` replaces the framework layout: a nonce'd style, a `main`, an `h1`, a link home, `noindex`, no scripts and no database, so it also works for a 500 or 503 |
| The dev mail index lacked a landmark and had links under the 24 px target size (`landmark-one-main`, `region`, `target-size`) | `/dev/mail` (development only) | A `main`, and padding on the links, in a nonce'd style |
| A **hidden checkbox with a hidden label** at desktop width: the CLS fix (below) made the search filters a CSS-only toggle below `lg`, and at `lg` its label is `display:none`, so the checkbox was a focusable control with no name (`label`, critical). Caught by the existing "without JavaScript" search test, **not** by the sweep with JavaScript on, hence the sweep now also judges the public pages' server HTML | `FilterSheet`, pre-hydration | The checkbox is `d-lg-none` too |
| **Focus after a page change**: Inertia swaps the page without loading it, so focus stayed on the clicked link, which is gone: a keyboard user started again from the top of the document and a screen reader said nothing about the new page | Every link, every redirect after a form | `resources/js/app.tsx` focuses the new page's `h1` when the path changes (see below) |
| **Focus lost after an action on the same page**: confirming "Cancel reservation" in its dialog closed the dialog, the toast said "Your reservation is cancelled.", and focus was on `body` | Every confirm dialog on a page that stays | The same handler: after a POST/PUT/PATCH/DELETE whose focused element is gone, focus the `h1` |
| `ConfirmButton`'s page button opens a dialog but didn't say so | `ConfirmButton` | `aria-haspopup="dialog"` (which is also how the sweep finds every dialog) |

**How the focus handler decides** (`focusHeadingAfterVisit`): it never acts for a poll or any partial reload (`only`), a plain GET reload (the hold page's `router.reload()` at expiry), or a form with errors (same path; its error summary takes focus). It doesn't act when something took focus on purpose (`[role="alert"]`: `FlashError`, the error summary). It acts when the path changed, or after an action when focus is on `body` or on a removed element. The heading gets `tabindex="-1"` (not a tab stop). Back and forward are covered by `navigate`; a post that redirects to the same URL only fires `success`, so both events are used.

## Keyboard-only walkthrough (7.2)

Method: Chromium, desktop 1280, **only `page.keyboard`** to operate the app (Tab, Shift+Tab, Enter, Space, arrows, Escape), reading `document.activeElement` after each key. Dev stack, demo data, as `renter1` and `owner`. The script is a one-off (not in the suite: it creates and cancels a hold).

**Reserve flow (renter):**

| Step | Result |
| --- | --- |
| Log in | Tab reaches Email after 5 presses (skip link, brand, Log in, Sign up first); Enter in the password field logs in; focus lands on the home `h1` |
| Skip link | The first Tab on every page is "Skip to content"; Enter moves focus to `main` |
| Search from home | Tab reaches the city select after 1 press; arrows choose; the Search button is 7 stops later (the native date field has four stops of its own: month, day, year, picker). The form is a plain GET, so the results are a full page load and focus is at the top of the document, like any non-script link |
| Results to a listing | The first card link is 33 stops in (the header and the whole filter form come first; the skip link goes to `main`, not past the filters); each card is one link (the name, `stretched-link`) |
| Reserve | On the listing, the first Reserve button is 12 stops in; Enter creates the hold and lands on `/reservations/{id}` (a plain form post, so focus is at the top of the document and the screen reader reads the new title "Your hold") |
| Hold page | `h1` "Your hold"; Cancel reservation is 11 stops in; Enter opens the dialog and focus goes into it (the dialog container); Escape closes it and focus returns to the Cancel button; confirming with Enter closes it, the toast says "Your reservation is cancelled." and, since 7.2, focus is on the page `h1` (before: `body`) |

**Landlord decision flow (owner):**

| Step | Result |
| --- | --- |
| Reservations list | The first row link is 11 stops in; Enter opens it and focus lands on the `h1` "Reservation for …" (before 7.2 the focus was lost) |
| Decision panel | Decline is 2 stops after the heading (Ask for a new proof, Decline, Confirm reservation); Enter opens the dialog with focus inside it |
| Dialog | Tab order: Close, the reason field, Keep it, Decline reservation, then back to the dialog (the focus trap works on this page); confirming with no reason shows "Write a reason. The renter will see it." and focus stays on the button; Escape returns focus to Decline |

**Not fixed (backlog)**: on the hold page, Tab from the last button of the cancel dialog goes to `body` and then through the three header stops (skip link, brand, account menu) before React-Bootstrap's focus enforcement pulls it back into the dialog (the same dialog on the landlord's reservation page cycles correctly). The dialog is `aria-modal`, so a screen reader treats the page behind it as inert, but a sighted keyboard user can see focus leave. A sound fix is `inert` on the page behind every dialog (`#app` while one is open) in a shared place, and it needs its own tests, so it is not a drive-by change here.

## Screen reader

**No screen reader was available** in the environment this slice was built in (a WSL2 container: no Orca, no audio, no VoiceOver). I did not do a screen-reader pass and do not claim one. What I did instead, to see what a screen reader is given: Playwright's accessibility-tree snapshot (`locator.ariaSnapshot()`) of `main` on the hold page and on the landlord's reservation page, read as a screen reader would:

- The hold page: `heading "Your hold" [level=1]`, the status as a paragraph, `heading "Pay the reservation fee" [level=2]`, the countdown in a paragraph ("Your hold ends in 24 hours until 1:34 PM, Wed 18 Nov, Manila time"), the fee in bold, the payment instructions under a level-3 heading, the landlord and phone as a term/definition list (the phone is a `tel:` link), `form "Proof of payment"` with each field labelled and its help text, then "Your stay" as a definition list. Heading levels don't skip, every control has a name, and the form has a name.
- The landlord's page: the landlord menu is a `navigation "Landlord"`, then the back link, `heading … [level=1]`, the status, "What to do next" with the three decisions as named buttons, the details as definition lists, the renter's email and phone as links, the proof as a list item with its amount and reference.

That shows names, roles and the heading outline, not how a screen reader speaks it: live-region behaviour (the toast's `role="status"`, the hold countdown's polite announcements at 1 hour and 10 minutes), dialog announcement and reading order are only checked in markup. **Backlog**: passes with NVDA (Windows), VoiceOver (macOS and iOS) and TalkBack on the reserve and decision flows, before anything calls the app screen-reader-tested.

## Known limits of the automated check

axe finds roughly a third of WCAG problems; it can't judge whether alt text is good, whether a heading says the right thing, or the reading order. Those were checked by reading the markup and the trees above, not proven. Colour contrast is axe's, on computed colours at both viewports, in the light theme (there's no dark mode: backlog).
