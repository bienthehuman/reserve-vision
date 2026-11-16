import { expect, test, type Browser, type Page } from '@playwright/test';
import { collectConsoleErrors, expectNoAxeViolations, logIn } from './support';

/**
 * Phase 3 demo (slice 3.2), the PoC gate: the landlord pages on the demo data
 * (README, "Demo logins"). The owner sees the account's reservations, including the
 * "last bed" room's; the caretaker sees the same list but can't change a rate; owner2,
 * of another account, gets the 404 page on the owner's reservation. Zero axe
 * violations on every landlord page. Read-only: it changes nothing, so both
 * viewport projects can run it. The phase 2 race (renter1 wins, renter2 is told
 * there is no space) is e2e/reserve.spec.ts, which runs in the same suite.
 */

/** Each test visits several pages and runs axe on each, while the other workers load the same stack. */
test.describe.configure({ timeout: 90_000 });

interface Session {
    page: Page;
    errors: string[];
    close: () => Promise<void>;
}

async function loggedIn(browser: Browser, email: string, viewport: { width: number; height: number }): Promise<Session> {
    const context = await browser.newContext({ viewport, isMobile: viewport.width < 600, hasTouch: viewport.width < 600 });
    const page = await context.newPage();
    const errors = await collectConsoleErrors(page);

    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await logIn(page, email, 'password');
    await expect(page).toHaveURL(/\/landlord$/);

    return { page, errors, close: () => context.close() };
}

/** No console or CSP errors, then zero axe violations; axe's own injected <style> is a CSP error afterwards, so the list restarts. */
async function expectCleanPage(session: Session): Promise<void> {
    expect(session.errors).toEqual([]);
    await expectNoAxeViolations(session.page);
    session.errors.length = 0;
}

/** The "Last Bed Room" reservation among the account's confirmed ones (the seeder confirms beds A to C), as a path. */
async function lastBedReservationPath(page: Page): Promise<string> {
    for (let number = 1; number <= 5; number++) {
        await page.goto(`/landlord/reservations?status=confirmed&page=${number}`);
        const row = page.locator('tbody tr', { hasText: 'Last Bed Room' }).first();

        if ((await row.count()) > 0) {
            const href = await row.getByRole('link').first().getAttribute('href');
            expect(href).toMatch(/^\/landlord\/reservations\/\d+$/);

            return href ?? '';
        }
    }

    throw new Error('No confirmed reservation of the Last Bed Room on the first 5 pages: is the demo data seeded?');
}

/** From the properties list to the Santos Bedspace House page, by its link's address (a full page load: no waiting on hydration). */
async function openProperty(page: Page): Promise<void> {
    await page.goto('/landlord/properties');
    const href = await page.getByRole('link', { name: 'Santos Bedspace House' }).getAttribute('href');
    await page.goto(href ?? '/landlord/properties');
}

test('Phase 3 demo: the owner sees the account\'s reservations, with the last-bed room\'s', async ({ browser, viewport }) => {
    const owner = await loggedIn(browser, 'owner@example.com', viewport ?? { width: 360, height: 800 });
    const { page } = owner;

    // The menu: all four links for an owner.
    const menu = page.getByRole('navigation', { name: 'Landlord' });
    for (const name of ['Dashboard', 'Reservations', 'Properties', 'Members']) {
        await expect(menu.getByRole('link', { name })).toBeVisible();
    }
    await expectCleanPage(owner);

    await page.goto('/landlord/reservations');
    await expect(page.getByRole('heading', { level: 1, name: 'Reservations' })).toBeVisible();
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await expect(menu.getByRole('link', { name: 'Reservations' })).toHaveAttribute('aria-current', 'page');
    await expectCleanPage(owner);

    const detailPath = await lastBedReservationPath(page);
    await expectCleanPage(owner);

    // The detail page is the one place with contact details.
    await page.goto(detailPath);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Reservation for');
    await expect(page.getByText('Last Bed Room', { exact: false }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: /@example\.com$/ })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Proof of payment' })).toBeVisible();
    await expectCleanPage(owner);

    await page.goto('/landlord/properties');
    await expect(page.getByRole('link', { name: 'Santos Bedspace House' })).toBeVisible();
    await expectCleanPage(owner);

    await openProperty(page);
    await expect(page.getByRole('heading', { level: 1, name: 'Santos Bedspace House' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 3, name: /Last Bed Room/ })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Edit room Last Bed Room' })).toBeVisible();
    await expectCleanPage(owner);

    await page.goto('/landlord/members');
    await expect(page.getByRole('heading', { level: 1, name: 'Members' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Send invitation' })).toBeVisible();
    await expectCleanPage(owner);

    await owner.close();
});

test('Phase 3 demo: the caretaker sees the same reservations, but cannot change a rate or invite', async ({ browser, viewport }) => {
    const size = viewport ?? { width: 360, height: 800 };
    const owner = await loggedIn(browser, 'owner@example.com', size);
    // Active stays: other specs running in parallel add, cancel and (6.1) confirm reservations on this account; none makes one active.
    await owner.page.goto('/landlord/reservations?status=active');
    const ownersFirstRow = await owner.page.locator('tbody tr').first().innerText();
    await owner.close();

    const caretaker = await loggedIn(browser, 'caretaker@example.com', size);
    const { page } = caretaker;

    const menu = page.getByRole('navigation', { name: 'Landlord' });
    await expect(menu.getByRole('link', { name: 'Reservations' })).toBeVisible();
    await expect(menu.getByRole('link', { name: 'Properties' })).toBeVisible();
    await expect(menu.getByRole('link', { name: 'Members' })).toHaveCount(0);
    await expectCleanPage(caretaker);

    await page.goto('/landlord/reservations?status=active');
    expect(await page.locator('tbody tr').first().innerText()).toBe(ownersFirstRow);
    await expectCleanPage(caretaker);

    const detailPath = await lastBedReservationPath(page);
    await page.goto(detailPath);
    await expect(page.getByRole('link', { name: /@example\.com$/ })).toBeVisible(); // a caretaker may see contact details
    await expectCleanPage(caretaker);

    await openProperty(page);
    await expect(page.getByRole('heading', { level: 3, name: /Last Bed Room/ })).toBeVisible();
    await expect(page.getByRole('link', { name: /^Edit / })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /^(Archive|Rename|Add a bed)/ })).toHaveCount(0);
    await expectCleanPage(caretaker);

    // Absent from the page is not the same as refused by the server: ask the server directly, as the page would.
    const statuses = await page.evaluate(async () => {
        const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        const send = async (method: string, url: string, body: object): Promise<number> =>
            (
                await fetch(url, {
                    method,
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
                    body: JSON.stringify(body),
                })
            ).status;

        return {
            rate: await send('PUT', '/landlord/rooms/1', { name: 'x', room_type: 'shared', monthly_rate: '1', reservation_fee: '0', deposit_months: 1, advance_months: 1 }),
            invite: await send('POST', '/landlord/invitations', { email: 'nobody@example.com' }),
        };
    });
    expect(statuses).toEqual({ rate: 403, invite: 403 });

    const members = await page.goto('/landlord/members');
    expect(members?.status()).toBe(403);

    await caretaker.close();
});

test('Phase 3 demo: owner2 gets the 404 page on the owner\'s reservation', async ({ browser, viewport }) => {
    const size = viewport ?? { width: 360, height: 800 };
    const owner = await loggedIn(browser, 'owner@example.com', size);
    const detailPath = await lastBedReservationPath(owner.page);
    await owner.close();

    const owner2 = await loggedIn(browser, 'owner2@example.com', size);
    const { page } = owner2;

    const response = await page.goto(detailPath);
    expect(response?.status()).toBe(404);
    await expect(page.getByText(/not found/i).first()).toBeVisible();
    await expect(page.getByText('Last Bed Room')).toHaveCount(0);
    await expect(page.getByText('Santos')).toHaveCount(0);

    // Their own pages show their own account only.
    await page.goto('/landlord/reservations');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await expect(page.locator('tbody')).not.toContainText('Last Bed Room');
    await expect(page.locator('tbody')).not.toContainText('Santos Bedspace House');

    await owner2.close();
});
