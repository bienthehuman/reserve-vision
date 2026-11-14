# 0006. Use Bootstrap 5 via Sass, not a utility-first framework

- Status: Proposed
- Date: 2026-11-14

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
- Bad: stock Bootstrap is recognizable. A custom theme (colors, type scale, radii, spacing) is set in `resources/scss/_theme.scss` in slice 1.
