import { expect, test, type Browser, type Page } from '@playwright/test';
import { collectConsoleErrors, expectNoAxeViolations, logIn } from './support';

/**
 * Slice 6.2 demo, phone viewport, against both stacks. renter1 on the demo owner's Magno Rooms for Rent (README, "Demo
 * logins"), where the seed gives renter1 only a finished viewing, and two open ones elsewhere (so one place is left
 * under the limit of 3):
 *
 * 1. renter1 asks for a viewing on the listing page (the form starts at the earliest open slot), picking a day 3 days
 *    ahead and a time, with a message; "My viewings" shows it as Requested, with no phone number or address yet.
 * 2. The caretaker opens it from the Viewings list, sees renter1's email and phone, and accepts it with a note.
 * 3. renter1 sees Accepted, the note, the address and the landlord's phone number.
 * 4. renter1 cancels it, and it moves to Past. That frees the slot again, so reruns on one stack keep working.
 *
 * Zero axe violations on every page in every state shown. A run that died halfway leaves an open request at Magno; the
 * next run cancels it first (one open viewing per property).
 */

const PHONE = { viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true };
const LISTING = '/listings/makati/magno-rooms-for-rent';
const PROPERTY = 'Magno Rooms for Rent';
const MESSAGE = 'E2E: I would like to see the corner room, and bring my mother.';
const NOTE = 'Ring the bell at the green gate and ask for Ate Elena.';

/** A day `days` from today in Manila, "YYYY-MM-DD". */
function manilaDate(days: number): string {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());
    const date = new Date(`${today}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

interface Session {
    page: Page;
    errors: string[];
    close: () => Promise<void>;
}

async function loggedIn(browser: Browser, email: string): Promise<Session> {
    const context = await browser.newContext(PHONE);
    const page = await context.newPage();
    const errors = await collectConsoleErrors(page);
    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await logIn(page, email, 'password');
    await expect(page.getByRole('button', { name: /account menu$/ })).toBeVisible();

    return { page, errors, close: () => context.close() };
}

/** No console or CSP error so far, and no axe violation; then forgets axe's own CSP error (CLAUDE.md quirks, 1.1). */
async function expectClean(session: Session): Promise<void> {
    await session.page.waitForLoadState('networkidle');
    expect(session.errors).toEqual([]);
    await expectNoAxeViolations(session.page);
    session.errors.length = 0;
}

async function cancelRequest(page: Page): Promise<void> {
    await page.getByRole('button', { name: `Cancel request for ${PROPERTY}` }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Cancel request' }).click();
}

test('Phase 6 viewings: renter1 asks, the caretaker accepts with a note, renter1 sees the phone number, then cancels', async ({ browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'phone-360', 'one run per stack: each run takes the renter\'s one request at Magno');
    test.setTimeout(120_000);

    // 1. renter1 asks for a viewing.
    const renter = await loggedIn(browser, 'renter1@example.com');
    await renter.page.goto('/viewings');
    await renter.page.waitForLoadState('networkidle');
    while (await renter.page.getByRole('button', { name: `Cancel request for ${PROPERTY}` }).count()) {
        await cancelRequest(renter.page); // left by a run that died halfway
        await expect(renter.page.getByRole('status')).toContainText('Your viewing request is cancelled.');
    }
    renter.errors.length = 0;

    await renter.page.goto(`${LISTING}#viewing`);
    await renter.page.waitForLoadState('networkidle');
    const form = renter.page.getByRole('form', { name: 'Ask for a viewing' });
    await expect(form).toBeVisible();
    // The form starts at the earliest open slot: the first day on offer, and a time that isn't disabled.
    const day = form.getByLabel('Day', { exact: true });
    await expect(day.locator('option').first()).toHaveAttribute('value', /^\d{4}-\d{2}-\d{2}$/);
    expect(await day.evaluate((select: HTMLSelectElement) => select.selectedIndex)).toBe(0);
    expect(await form.getByLabel('Time (Manila)').evaluate((select: HTMLSelectElement) => select.selectedOptions[0]?.disabled)).toBe(false);
    await expectClean(renter);

    const when = manilaDate(3);
    await day.selectOption(when);
    await form.getByLabel('Time (Manila)').selectOption('10:00');
    await form.getByLabel('Message').fill(MESSAGE);
    await form.getByRole('button', { name: 'Ask for a viewing' }).click();
    await expect(renter.page).toHaveURL(/\/viewings$/);
    await expect(renter.page.getByRole('status')).toContainText('Viewing requested.');
    const mine = renter.page.locator('section[aria-labelledby="coming-title"] li', { hasText: PROPERTY });
    await expect(mine.locator('[data-status="requested"]')).toHaveText('Requested');
    await expect(mine).toContainText('10:00 AM');
    await expect(mine).toContainText('Manila time');
    await expect(mine).toContainText(MESSAGE);
    await expect(mine).not.toContainText('Call'); // no phone number or address until accepted
    await expectClean(renter);

    // 2. The caretaker opens it, sees the renter's contact details, and accepts with a note.
    const caretaker = await loggedIn(browser, 'caretaker@example.com');
    await caretaker.page.goto('/landlord/viewings');
    const row = caretaker.page.locator('tbody tr', { hasText: PROPERTY }).filter({ hasText: 'Jasmine' }).filter({ hasText: 'Requested' });
    await expect(row.first()).toBeVisible();
    await expect(row.first()).not.toContainText('@'); // the list has the name only
    await expectClean(caretaker);
    const href = await row.first().getByRole('link').first().getAttribute('href');
    await caretaker.page.goto(href ?? '');
    await caretaker.page.waitForLoadState('networkidle');
    await expect(caretaker.page.getByRole('link', { name: 'renter1@example.com' })).toBeVisible();
    await expect(caretaker.page.getByText(MESSAGE)).toBeVisible();
    await expect(caretaker.page.getByRole('button', { name: 'Mark as done' })).toHaveCount(0); // only an accepted viewing can be done
    await expectClean(caretaker);

    await caretaker.page.getByLabel('Note to the renter').fill(NOTE);
    await caretaker.page.getByRole('button', { name: 'Accept', exact: true }).click();
    await expect(caretaker.page.getByRole('status')).toContainText('Viewing accepted.');
    await expect(caretaker.page.locator('[data-status="accepted"]')).toBeVisible();
    await expect(caretaker.page.getByRole('button', { name: 'Decline instead' })).toBeEnabled(); // an answer can change until the time
    await expect(caretaker.page.getByRole('button', { name: 'Mark as done' })).toBeDisabled(); // accepted now, but the time hasn't come
    await expectClean(caretaker);
    await caretaker.close();

    // 3. renter1 sees it accepted, with the note, the address and the phone number.
    await renter.page.goto('/viewings');
    await renter.page.waitForLoadState('networkidle');
    await expect(mine.locator('[data-status="accepted"]')).toHaveText('Accepted');
    await expect(mine).toContainText(NOTE);
    await expect(mine.getByRole('definition').filter({ hasText: 'Makati' }).first()).toBeVisible(); // the full address
    await expect(mine.getByRole('link', { name: /^0\d{10}$/ })).toBeVisible(); // the landlord's contact phone
    await expectClean(renter);

    // 4. renter1 cancels.
    await cancelRequest(renter.page);
    await expect(renter.page.getByRole('status')).toContainText('Your viewing request is cancelled.');
    await expect(renter.page.locator('section[aria-labelledby="coming-title"]')).not.toContainText(PROPERTY);
    await expect(renter.page.locator('section[aria-labelledby="past-title"] li', { hasText: PROPERTY }).locator('[data-status="cancelled"]').first()).toBeVisible();
    await expectClean(renter);
    await renter.close();
});
