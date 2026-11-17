// Lighthouse CI (slice 7.2): mobile emulation (Moto G Power class, simulated slow 4G, which is
// Lighthouse's default), 3 runs per page, the median reported. Run against the PRODUCTION stack
// only (the dev stack's Vite server gives meaningless numbers):
//
//   LHCI_BASE_URL=http://localhost:8280 npx lhci autorun
//
// Thresholds live in lighthouse-thresholds.cjs so the one Performance bar is easy to find.
const { performance: performanceBar } = require('./lighthouse-thresholds.cjs');

const base = (process.env.LHCI_BASE_URL ?? 'http://localhost:8280').replace(/\/$/, '');

module.exports = {
    ci: {
        collect: {
            url: [
                `${base}/`,
                `${base}/listings?city=manila`,
                `${base}/listings/manila/santos-bedspace-house`,
                `${base}/login`,
            ],
            numberOfRuns: 3,
            settings: {
                // The CI stack is plain HTTP on localhost (Caddy `:80`), so these two audits can only fail
                // for a reason a real deployment (HTTPS, DEPLOY.md) doesn't have. They are skipped, not
                // hidden: the job summary says so (docs/perf/lighthouse.md, "What is not measured").
                skipAudits: ['is-on-https', 'redirects-http'],
                chromeFlags: '--no-sandbox --headless=new',
            },
        },
        assert: {
            // Every page: Accessibility 100, Best Practices >= 95, CLS <= 0.1 and the Performance bar.
            // SEO >= 95 on the public pages only: /login is `noindex` on purpose (SEO is ~54 there by design).
            assertMatrix: [
                {
                    matchingUrlPattern: '.*',
                    aggregationMethod: 'median-run',
                    assertions: {
                        'categories:accessibility': ['error', { minScore: 1 }],
                        'categories:best-practices': ['error', { minScore: 0.95 }],
                        'categories:performance': ['error', { minScore: performanceBar / 100 }],
                        'cumulative-layout-shift': ['error', { maxNumericValue: 0.1 }],
                    },
                },
                {
                    matchingUrlPattern: '^(?!.*/login).*$',
                    aggregationMethod: 'median-run',
                    assertions: { 'categories:seo': ['error', { minScore: 0.95 }] },
                },
            ],
        },
    },
};
