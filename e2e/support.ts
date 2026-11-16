import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

/** True against a production stack (E2E_SKIP_DEV_PAGES=1): no /styleguide and no Mailpit there. */
export const isProductionRun = process.env.E2E_SKIP_DEV_PAGES === '1';

/** Mailpit's web/API port in the dev stack (FORWARD_MAILPIT_DASHBOARD_PORT). */
export const mailpitUrl = process.env.E2E_MAILPIT_URL ?? 'http://localhost:8126';

/** Collects console errors, including CSP violations; assert the result is empty before running axe. */
export async function collectConsoleErrors(page: Page): Promise<string[]> {
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

/** Zero axe violations (WCAG 2.2 AA + best practices). Run after the console check: axe injects a <style> the CSP blocks. */
export async function expectNoAxeViolations(page: Page): Promise<void> {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'])
        .analyze();

    expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
}

/** Opens the header's account menu (the user's first name) and returns it; Log out is inside. */
export async function openAccountMenu(page: Page) {
    const nav = page.getByRole('navigation', { name: 'Account' });
    await nav.getByRole('button', { name: /account menu$/ }).click();

    return nav;
}

/** Fills and submits the login form on the current page (wait for hydration first). */
export async function logIn(page: Page, email: string, password: string): Promise<void> {
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
}

/**
 * Chooses an address on the property form (slice 4.4): province, then city, then barangay, each dropdown
 * waiting until the one before it has loaded its list. Labels are the names shown, as a person picks them.
 */
export async function chooseAddress(page: Page, place: { province: string; city: string; barangay: string }): Promise<void> {
    const city = page.getByLabel('City or municipality', { exact: true });
    // Again until the city opens up: a choice made before hydration under load can be lost (CLAUDE.md, quirks in 4.4).
    await expect(async () => {
        await page.getByLabel('Province', { exact: true }).selectOption({ label: place.province });
        await expect(city).toBeEnabled({ timeout: 2_000 });
    }).toPass({ timeout: 20_000 });
    await city.selectOption({ label: place.city });
    const barangay = page.getByLabel('Barangay', { exact: true });
    await expect(barangay).toBeEnabled();
    await barangay.selectOption({ label: place.barangay });
}

/** Loyola Heights, Quezon City: the address the e2e properties use. */
export const LOYOLA_HEIGHTS = { province: 'Metro Manila', city: 'Quezon City', barangay: 'Loyola Heights' };
