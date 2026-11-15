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

/** Fills and submits the login form on the current page (wait for hydration first). */
export async function logIn(page: Page, email: string, password: string): Promise<void> {
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
}
