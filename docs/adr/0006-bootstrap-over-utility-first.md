# 0006. Use Bootstrap 5 via Sass, not a utility-first framework

- Status: Accepted (2026-11-17)
- Date: 2026-11-14
- Updated: 2026-11-17 (as built in v1, last section)

## Context

The original thesis used Bootstrap. The other portfolio projects use different styling approaches. The risk with Bootstrap is that it looks generic and ships too much CSS.

## Options

1. **Bootstrap 5 via Sass + React-Bootstrap**, themed through Sass variables, importing only the partials used.
2. **Tailwind CSS**: utility-first, small output, but the same choice as elsewhere in the portfolio.

## Decision

Option 1.

## Consequences

- Good: a direct then-vs-now comparison with the thesis; accessible components (modals, dropdowns) from React-Bootstrap.
- Good: shows a second styling approach across the portfolio.
- Bad: CSS size needs active control. Only the partials we use are imported, and Lighthouse records the result.
- Bad: stock Bootstrap is recognizable. A custom theme (colors, type scale, radii, spacing) is set in `resources/scss/_theme.scss` in slice 1 (Update 2026-11-17: with `_tokens.scss` beside it, below).

## Update 2026-11-17: as built in v1

- Only the Bootstrap partials the pages use are imported (`resources/scss/app.scss`), and `app.css` is held under 35 kB gzipped by a CI budget (`bin/check-css-budget.mjs`, slice 7.2). One stylesheet serves every page.
- The theme moved out of one file: `_tokens.scss` holds the colors, fonts and radii, `_theme.scss` maps them onto Bootstrap's variables, and `_components.scss` styles the project's own pieces ([DESIGN_SYSTEM.md](../DESIGN_SYSTEM.md#bootstrap-sass-setup)). It was retuned once, in the UI review after slice 4.2.
- React-Bootstrap's `Modal` and `Offcanvas` are used; its `Dropdown` is not (it positions with Popper's inline styles, which the CSP forbids), so the account menu is a plain disclosure button. The comboboxes are Downshift, styled with Bootstrap's form classes.
