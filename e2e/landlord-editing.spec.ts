import { expect, test } from '@playwright/test';
import { collectConsoleErrors, expectNoAxeViolations, logIn } from './support';

/**
 * Slice 4.1: the owner creates a property with a shared room of 3 beds, edits the rate and archives a bed,
 * on the phone viewport. Zero axe violations on every new page. It adds a property to the demo owner's
 * account on each run (a unique name), so it runs in one project only and leaves the rest of the demo data alone.
 * Set SCREENSHOT_DIR to save the 360 px screenshots for the PR.
 */

test.describe.configure({ timeout: 120_000 });

test('4.1: the owner adds a property with a 3-bed room, changes the rate and archives a bed', async ({ browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'phone-360', 'Phone viewport only: one run adds one property');

    const context = await browser.newContext({ viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true });
    const page = await context.newPage();
    const errors = await collectConsoleErrors(page);
    const shot = async (name: string) => {
        if (process.env.SCREENSHOT_DIR) {
            await page.screenshot({ path: `${process.env.SCREENSHOT_DIR}/${name}.png`, fullPage: true });
        }
    };
    /** No console or CSP errors, then zero axe violations; axe's own <style> is a CSP error afterwards, so the list restarts. */
    const clean = async () => {
        expect(errors).toEqual([]);
        await expectNoAxeViolations(page);
        errors.length = 0;
    };

    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await logIn(page, 'owner@example.com', 'password');
    await expect(page).toHaveURL(/\/landlord$/);
    await page.waitForLoadState('networkidle');

    // The dashboard: four figures, each a link.
    await expect(page.getByRole('heading', { level: 2, name: 'Payments to review' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Occupancy today' })).toBeVisible();
    await shot('dashboard');
    await clean();

    // A new property, as a draft.
    const name = `E2E Bedspace ${Date.now()}`;
    await page.goto('/landlord/properties');
    await page.waitForLoadState('networkidle');
    await page.getByRole('link', { name: 'Add a property' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Add a property' })).toBeVisible();
    await page.waitForLoadState('networkidle');
    await page.getByLabel('Property name', { exact: true }).fill(name);
    await page.getByLabel('Street address').fill('12 Mabini Street');
    await page.getByLabel('Barangay', { exact: true }).fill('Loyola Heights');
    await page.getByLabel('City', { exact: true }).selectOption('quezon-city');
    await expect(page.getByLabel('Province')).toHaveValue('Metro Manila'); // follows from the city
    await page.getByLabel('How to pay the reservation fee').fill('GCash 0917 123 4567 (E. Test)');
    await shot('property-form');
    await clean();
    await page.getByRole('button', { name: 'Save as a draft' }).click();
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
    await expect(page.getByText('is saved as a draft')).toBeVisible();
    await expect(page.getByText('12 Mabini Street, Loyola Heights, Quezon City, Metro Manila')).toBeVisible();
    await clean();

    // A shared room of 3 beds.
    await page.getByRole('link', { name: 'Add a room' }).click();
    await page.waitForLoadState('networkidle');
    await page.getByLabel('Room name', { exact: true }).fill('Room 1');
    await page.getByLabel('Room type', { exact: true }).selectOption('shared');
    await page.getByLabel('Number of beds').fill('3');
    await page.getByLabel('Monthly rate (₱)').fill('3,000');
    await page.getByLabel('Reservation fee (₱)').fill('500');
    await shot('room-form');
    await clean();
    await page.getByRole('button', { name: 'Add room' }).click();
    await expect(page.getByRole('heading', { level: 3, name: /Room 1/ })).toBeVisible();
    for (const bed of ['Bed A', 'Bed B', 'Bed C']) {
        await expect(page.getByRole('button', { name: `Rename ${bed}` })).toBeVisible();
    }
    await expect(page.getByText('₱3,000')).toBeVisible();
    await shot('property-with-beds');
    await clean();

    // Edit the rate.
    await page.getByRole('link', { name: 'Edit room Room 1' }).click();
    await page.waitForLoadState('networkidle');
    await page.getByLabel('Monthly rate (₱)').fill('3500');
    await clean();
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Saved Room 1.')).toBeVisible();
    await expect(page.getByText('₱3,500')).toBeVisible();

    // Archive a bed.
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: 'Archive Bed C' }).click();
    await expect(page.getByText('Bed C is archived.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Restore Bed C' })).toBeVisible();
    await clean();

    await context.close();
});
