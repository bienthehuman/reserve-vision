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

/** Types into a dropdown you can type into (ComboboxField) and clicks the option with that exact name. */
export async function chooseFrom(page: Page, label: string, option: string, typed = option, timeout?: number): Promise<void> {
    await page.getByRole('combobox', { name: label, exact: true }).fill(typed, { timeout });
    await page.getByRole('option', { name: option, exact: true }).click({ timeout });
}

/**
 * Chooses an address on the property form (slice 4.4): province, then city, then barangay, each waiting
 * until the one before it has loaded its list. Options by the names shown (Manila's with the district:
 * "Barangay 395, Sampaloc").
 */
export async function chooseAddress(page: Page, place: { province: string; city: string; barangay: string }): Promise<void> {
    const city = page.getByRole('combobox', { name: 'City or municipality', exact: true });
    // Again until the city opens up: typing before hydration under load does nothing (CLAUDE.md, quirks in 4.4).
    await expect(async () => {
        await chooseFrom(page, 'Province', place.province, place.province, 3_000);
        await expect(city).toBeEnabled({ timeout: 2_000 });
    }).toPass({ timeout: 20_000 });
    await chooseFrom(page, 'City or municipality', place.city);
    await expect(page.getByRole('combobox', { name: 'Barangay', exact: true })).toBeEnabled();
    await chooseFrom(page, 'Barangay', place.barangay);
}

/** Loyola Heights, Quezon City: the address the e2e properties use. */
export const LOYOLA_HEIGHTS = { province: 'Metro Manila', city: 'Quezon City', barangay: 'Loyola Heights' };

/**
 * Axe on a page that has JavaScript switched off (axe can't run inside one). The server's HTML, minus its scripts,
 * is put into a normal page of the same origin, so the same styles and the same markup are judged.
 */
export async function expectNoAxeViolationsWithoutScripts(page: Page): Promise<void> {
    const origin = new URL(page.url()).origin;
    const html = (await page.content()).replace(/<script[\s\S]*?<\/script>/gi, '');
    const context = await page.context().browser()!.newContext({ javaScriptEnabled: true, viewport: page.viewportSize() ?? undefined });
    const copy = await context.newPage();
    await copy.setContent(html.replace('<head>', `<head><base href="${origin}/">`), { waitUntil: 'load' });
    try {
        await expectNoAxeViolations(copy);
    } finally {
        await context.close();
    }
}
