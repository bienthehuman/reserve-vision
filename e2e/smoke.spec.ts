import { expect, test } from '@playwright/test';
import { collectConsoleErrors, expectNoAxeViolations, isProductionRun } from './support';

const pages = [
    { path: '/', heading: 'ReserveVision', devOnly: false },
    { path: '/styleguide', heading: 'Styleguide', devOnly: true },
];

for (const { path, heading, devOnly } of pages) {
    test.describe(path, () => {
        test.skip(devOnly && isProductionRun, 'dev-only page');

        test('renders, hydrates and has zero axe violations', async ({ page }) => {
            const errors = await collectConsoleErrors(page);

            const response = await page.goto(path);
            expect(response?.status()).toBe(200);
            await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible();
            await page.waitForLoadState('networkidle');

            // Checked before axe runs: axe-core injects its own <style>, which the CSP rightly blocks.
            expect(errors).toEqual([]);

            await expectNoAxeViolations(page);
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
