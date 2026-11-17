import { expect, test } from '@playwright/test';
import { chooseFrom, collectConsoleErrors, expectNoAxeViolations, expectNoAxeViolationsWithoutScripts } from './support';

/**
 * Slice 5.2: search. Read-only (it never reserves), so both viewports can run it next to the race.
 * Quezon City has demo listings in every seeding; the filters are chosen the way a visitor does.
 */

const isoDate = (daysAhead: number): string => {
    const date = new Date();
    date.setDate(date.getDate() + daysAhead);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

test('search Quezon City, a price range and a date, open a result', async ({ page }, testInfo) => {
    const errors = await collectConsoleErrors(page);
    const phone = (page.viewportSize()?.width ?? 1280) < 992;

    await page.goto('/listings');
    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('heading', { level: 1, name: 'All listings' })).toBeVisible();

    if (phone) {
        await page.getByRole('button', { name: 'Filters', exact: true }).click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await page.screenshot({ path: testInfo.outputPath('filter-sheet.png') });
    }

    // Hydration swaps the native selects for comboboxes: wait for them, then choose the place the way a visitor does.
    await expect(page.getByRole('combobox', { name: 'Province', exact: true })).toBeVisible();
    await chooseFrom(page, 'Province', 'Metro Manila');
    await expect(page.getByRole('combobox', { name: 'City or municipality', exact: true })).toBeEnabled();
    await chooseFrom(page, 'City or municipality', 'Quezon City');
    await page.getByLabel('Highest monthly price in pesos').fill('15000');
    await page.getByLabel('Lowest monthly price in pesos').fill('1000');
    await page.getByLabel('From', { exact: true }).fill(isoDate(14));
    await page.getByLabel('For', { exact: true }).selectOption('3');

    const apply = page.getByRole('button', { name: /^Show (\d+ places?|places)$/ });
    await expect(apply).toHaveText(/^Show \d+ places?$/);
    await apply.click();

    await expect(page).toHaveURL(/city=quezon-city/);
    await expect(page).toHaveURL(/max_price=15000/);
    await expect(page).toHaveURL(new RegExp(`move_in=${isoDate(14)}`));
    await expect(page.getByRole('heading', { level: 1, name: 'Search results' })).toBeVisible();
    const cards = page.locator('article.listing-card');
    await expect(cards.first()).toBeVisible();
    await expect(cards.first()).toContainText('Quezon City');
    await expect(cards.first()).toContainText(/\d+ rooms? match/);
    await expect(cards.first()).toContainText(/\d+ beds? free from/);
    await page.screenshot({ path: testInfo.outputPath('results.png'), fullPage: true });
    expect(errors).toEqual([]);
    await expectNoAxeViolations(page);
    errors.length = 0;

    // A result opens its listing with the stay carried over.
    const first = cards.first();
    const name = (await first.getByRole('heading', { level: 2 }).textContent()) ?? '';
    const href = await first.getByRole('link', { name }).getAttribute('href');
    expect(href).toContain(`move_in=${isoDate(14)}`);
    await page.goto(href ?? '/');
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
    expect(errors).toEqual([]);
    await expectNoAxeViolations(page);
});

test('no matches: an empty state with a link that clears the filters', async ({ page }, testInfo) => {
    const errors = await collectConsoleErrors(page);

    await page.goto('/listings?city=quezon-city&max_price=100');
    await page.waitForLoadState('networkidle');
    await expect(page.getByText('No places match these filters')).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('empty.png'), fullPage: true });
    expect(errors).toEqual([]);
    await expectNoAxeViolations(page);
    await page.locator('.empty-state').getByRole('link', { name: 'Clear filters' }).click();
    await expect(page).toHaveURL(/\/listings$/);
    await expect(page.locator('article.listing-card').first()).toBeVisible();
});

test('the home page search form goes to the results', async ({ page }) => {
    const errors = await collectConsoleErrors(page);

    await page.goto('/');
    await page.waitForLoadState('networkidle');
    const form = page.getByRole('search');
    await form.getByLabel('Where do you want to live?').selectOption('quezon-city');
    await form.getByLabel('Up to (₱/mo)').fill('20000');
    await form.getByRole('button', { name: 'Search' }).click();

    await expect(page).toHaveURL(/\/listings\?city=quezon-city&max_price=20000/);
    await expect(page.locator('article.listing-card').first()).toBeVisible();
    expect(errors).toEqual([]);
});

test.describe('without JavaScript', () => {
    test.use({ javaScriptEnabled: false });

    test('the filters are a plain GET form that narrows the results', async ({ page }) => {
        await page.goto('/listings?province=metro-manila');
        // On a phone the filters start collapsed behind a label (a visually hidden checkbox opens it, no script needed).
        const toggle = page.locator('label.filter-toggle');
        if (await toggle.isVisible()) {
            await toggle.click();
        }
        await page.getByLabel('City or municipality').selectOption('quezon-city');
        await page.getByLabel('Highest monthly price in pesos').fill('20000');
        await page.getByRole('button', { name: 'Show places' }).click();

        await expect(page).toHaveURL(/city=quezon-city/);
        await expect(page).toHaveURL(/max_price=20000/);
        await expect(page.locator('article.listing-card').first()).toBeVisible();
        await expect(page.locator('article.listing-card').first()).toContainText('Quezon City');
        await expectNoAxeViolationsWithoutScripts(page);
    });
});
