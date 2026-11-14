import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const pages = [
    { path: '/', heading: 'ReserveVision', devOnly: false },
    { path: '/styleguide', heading: 'Styleguide', devOnly: true },
];

/** Fails the test on any console error, including CSP violations. */
async function collectConsoleErrors(page: Page): Promise<string[]> {
    const errors: string[] = [];
    await page.addInitScript(() => {
        document.addEventListener('securitypolicyviolation', (event) => {
            console.error(`CSP violation: ${event.violatedDirective} at ${event.sourceFile}:${event.lineNumber} sample=${event.sample}`);
        });
    });
    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.push(message.text());
        }
    });
    page.on('pageerror', (error) => errors.push(error.message));

    return errors;
}

for (const { path, heading, devOnly } of pages) {
    test.describe(path, () => {
        test.skip(devOnly && process.env.E2E_SKIP_DEV_PAGES === '1', 'dev-only page');

        test('renders, hydrates and has zero axe violations', async ({ page }) => {
            const errors = await collectConsoleErrors(page);

            const response = await page.goto(path);
            expect(response?.status()).toBe(200);
            await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible();
            await page.waitForLoadState('networkidle');

            // Checked before axe runs: axe-core injects its own <style>, which the CSP rightly blocks.
            expect(errors).toEqual([]);

            const results = await new AxeBuilder({ page })
                .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'])
                .analyze();

            expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
        });
    });
}

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('the home page is complete, styled HTML', async ({ page }) => {
        await page.goto('/');

        await expect(page.getByRole('heading', { level: 1, name: 'ReserveVision' })).toBeVisible();
        await expect(page.getByText('with no double bookings')).toBeVisible();
        await expect(page.getByRole('search')).toBeVisible();
        await expect(page.getByLabel('Where do you want to live?')).toBeVisible();

        // Styled from SSR alone: the hero uses the ube-subtle background from the theme.
        const heroBackground = await page.locator('.hero').evaluate((el) => getComputedStyle(el).backgroundColor);
        expect(heroBackground).toBe('rgb(241, 236, 248)');
    });
});
