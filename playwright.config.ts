import { defineConfig, devices } from '@playwright/test';

/**
 * Smoke + accessibility suite. Runs against an already running stack:
 *   dev (default):  http://localhost:8180 (bin/setup)
 *   any other:      E2E_BASE_URL=https://… npm run e2e
 * Set E2E_SKIP_DEV_PAGES=1 against a production stack, where /styleguide is a 404.
 */
export default defineConfig({
    testDir: './e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8180',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'phone-360',
            testIgnore: /t12-full-flow/,
            use: { ...devices['Desktop Chrome'], viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true },
        },
        {
            name: 'desktop-1280',
            testIgnore: /t12-full-flow/,
            use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
        },
        {
            // T12 (slice 6.3): the full flow on a phone. It uses renter2 and the demo owner's account, so it runs alone,
            // after every other spec has finished (the Phase 2 and 5 demos also use renter2's two open reservations).
            name: 't12',
            testMatch: /t12-full-flow/,
            dependencies: ['phone-360', 'desktop-1280'],
            use: { ...devices['Desktop Chrome'], viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true },
        },
    ],
});
