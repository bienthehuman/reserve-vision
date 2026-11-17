import { expect, test, type APIRequestContext, type Browser, type Page } from '@playwright/test';
import path from 'node:path';
import { collectConsoleErrors, expectNoAxeViolations, isProductionRun, logIn, mailpitUrl } from './support';

/**
 * T12 and the phase 6 demo (slice 6.3), phone viewport, against both stacks: the whole flow, and the emails along it,
 * read from Mailpit's API (the dev stack's, or the CI production override's).
 *
 * 1. A guest searches for Baguio, opens the demo owner's Villanueva Dormitory, presses Reserve, logs in as renter2,
 *    comes back to the same room and reserves. renter2 gets the hold email, with the payment instructions.
 * 2. renter2 sends a proof. The owner gets the proof email; its button opens the reservation on the landlord's side.
 * 3. The caretaker opens the owner's link, confirms. renter2 gets the confirmation email; its button opens the hold page, confirmed.
 * 4. Cleanup: renter2 cancels, which frees the bed for the next run (and emails the landlord).
 *
 * Zero axe violations on every page. Each email is found by its recipient, subject and the reservation's own link, so a
 * mailbox full of earlier runs can't be mistaken for this one. Runs alone, after every other spec (see playwright.config.ts):
 * renter2 may not have two open reservations while the Phase 2 and 5 demos run.
 */

const PHONE = { viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true };
const RECEIPT = path.join('e2e', 'fixtures', 'receipt.jpg');
const PROPERTY = 'Villanueva Dormitory';

function manilaDate(days: number): string {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());
    const date = new Date(`${today}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

const MOVE_IN = manilaDate(85);

interface Session {
    page: Page;
    errors: string[];
    close: () => Promise<void>;
}

async function newSession(browser: Browser): Promise<Session> {
    const context = await browser.newContext(PHONE);
    const page = await context.newPage();
    const errors = await collectConsoleErrors(page);

    return { page, errors, close: () => context.close() };
}

async function loggedIn(browser: Browser, email: string): Promise<Session> {
    const session = await newSession(browser);
    await session.page.goto('/login');
    await session.page.waitForLoadState('networkidle');
    await logIn(session.page, email, 'password');
    await expect(session.page.getByRole('button', { name: /account menu$/ })).toBeVisible();

    return session;
}

/** No console or CSP error so far, then no axe violation; then forgets axe's own CSP error (CLAUDE.md quirks, 1.1). */
async function expectClean(session: Session): Promise<void> {
    expect(session.errors).toEqual([]);
    await expectNoAxeViolations(session.page);
    session.errors.length = 0;
}

/** Cancels every current reservation renter2 can still cancel (left by an earlier run). */
async function cancelLeftovers(page: Page): Promise<void> {
    await page.goto('/reservations');
    const links = await page.locator('section[aria-labelledby="current-title"] h3 a').evaluateAll((anchors) => anchors.map((a) => a.getAttribute('href') ?? ''));

    for (const href of links) {
        await page.goto(href);
        await page.waitForLoadState('networkidle');
        const cancel = page.getByRole('button', { name: 'Cancel reservation' });
        if (await cancel.isVisible()) {
            await cancel.click();
            await page.getByRole('dialog').getByRole('button', { name: 'Cancel reservation' }).click();
            await expect(page.locator('[data-status="cancelled"]').first()).toBeVisible();
        }
    }
}

interface Mail {
    subject: string;
    text: string;
    /** The href of the mail's one button, as the HTML part has it. */
    button: string;
}

/**
 * The newest mail to `to` with this subject whose button leads to `link`, polled for up to 60 s (the worker sends within seconds).
 */
async function waitForMail(request: APIRequestContext, to: string, subject: string, link: RegExp): Promise<Mail> {
    let found: Mail | null = null;

    await expect
        .poll(
            async () => {
                const search = await request.get(`${mailpitUrl}/api/v1/search`, { params: { query: `to:"${to}" subject:"${subject}"` } });
                const body = (await search.json()) as { messages: { ID: string }[] };

                for (const { ID } of body.messages) {
                    const message = (await (await request.get(`${mailpitUrl}/api/v1/message/${ID}`)).json()) as { Subject: string; Text: string; HTML: string };
                    const button = /<a href="([^"]+)"[^>]*class="button/.exec(message.HTML)?.[1]?.replaceAll('&amp;', '&') ?? '';
                    if (link.test(button)) {
                        found = { subject: message.Subject, text: message.Text, button };

                        return true;
                    }
                }

                return false;
            },
            { timeout: 60_000, message: `no mail "${subject}" to ${to} with a button matching ${link}` },
        )
        .toBe(true);

    return found as unknown as Mail;
}

/** The path of an emailed link: the stack under test may be reached by another host than APP_URL. */
function pathOf(link: string): string {
    const url = new URL(link);

    return url.pathname + url.search;
}

test('T12: search, reserve, proof, the caretaker confirms, and both emails arrive', async ({ browser, request }, testInfo) => {
    test.skip(testInfo.project.name !== 't12', 'one run per stack');
    test.skip(isProductionRun && !process.env.E2E_MAILPIT_URL, 'the production stack needs E2E_MAILPIT_URL (the CI override has a Mailpit)');
    test.setTimeout(240_000);

    // 0. A clean slate for renter2.
    const renter = await loggedIn(browser, 'renter2@example.com');
    await cancelLeftovers(renter.page);
    await renter.close();

    // 1. A guest searches, opens the listing and reserves, logging in on the way.
    const guest = await newSession(browser);
    const page = guest.page;
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    await expectClean(guest);
    await page.getByLabel('Where do you want to live?').selectOption({ label: 'Baguio' });
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await page.waitForLoadState('networkidle');
    await expectClean(guest);
    await page.locator('a[href^="/listings/baguio/villanueva-dormitory?"], a[href="/listings/baguio/villanueva-dormitory"]').first().click();
    await expect(page.getByRole('heading', { level: 1, name: PROPERTY })).toBeVisible();
    await page.waitForLoadState('networkidle');

    await page.getByLabel('Move-in date').fill(MOVE_IN);
    await page.getByLabel("How long you'll stay").selectOption('1');
    await page.getByRole('button', { name: 'Check free beds' }).click();
    await expect(page).toHaveURL(new RegExp(`move_in=${MOVE_IN}`));
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-testid^="free-spaces-"]').first()).toContainText(' for ');
    const free = await page.locator('[data-testid^="free-spaces-"]').evaluateAll((nodes) =>
        nodes
            .filter((node) => /^The room is free|^[1-9]\d* of \d+ beds? free/.test(node.textContent ?? ''))
            .map((node) => node.getAttribute('data-testid')?.replace('free-spaces-', '') ?? ''),
    );
    expect(free, 'a room with a free place for the stay').not.toEqual([]);
    await expectClean(guest);
    await page.locator(`#room-${free[0]}`).getByRole('button', { name: /^Reserve / }).click();

    await expect(page).toHaveURL(/\/login$/);
    await page.waitForLoadState('networkidle');
    await expectClean(guest);
    await logIn(page, 'renter2@example.com', 'password');
    await page.waitForURL(/villanueva-dormitory/);
    guest.errors.length = 0; // the login's Inertia 409 is logged as a failed resource (CLAUDE.md quirks, 5.3)
    await page.waitForLoadState('networkidle');
    await page.locator(`#room-${free[0]}`).getByRole('button', { name: /^Reserve / }).click();

    await expect(page).toHaveURL(/\/reservations\/\d+$/);
    const id = page.url().split('/').pop() ?? '';
    await expect(page.getByRole('heading', { level: 1, name: 'Your hold' })).toBeVisible();
    await page.waitForLoadState('networkidle');
    const instructions = ((await page.getByTestId('payment-instructions').innerText()) || '').trim();
    expect(instructions).not.toBe('');
    await expectClean(guest);

    // The hold email: the instructions, the deadline in Philippine time, and a button to this hold.
    const hold = await waitForMail(request, 'renter2@example.com', `Your hold at ${PROPERTY} is ready`, new RegExp(`/reservations/${id}$`));
    expect(hold.text).toContain(instructions.split('\n')[0] ?? '');
    expect(hold.text).toMatch(/Your hold ends .+ Philippine time/);
    expect(hold.text).toContain(PROPERTY);

    // 2. The proof.
    await page.getByLabel('Screenshot, photo or PDF of the receipt').setInputFiles(RECEIPT);
    await page.getByLabel('Reference number').fill('T12-0001');
    await page.getByRole('button', { name: 'Send proof' }).click();
    await expect(page.locator('[data-status="proof_uploaded"]')).toHaveText('Awaiting review');
    await expectClean(guest);

    // The owner's email; its button opens the reservation on the landlord's side.
    const proofMail = await waitForMail(request, 'owner@example.com', `sent proof of payment for ${PROPERTY}`, new RegExp(`/landlord/reservations/${id}$`));
    expect(proofMail.text).toContain('T12-0001');
    expect(proofMail.text).toMatch(/Amount sent: ₱[\d,]+/);

    const owner = await loggedIn(browser, 'owner@example.com');
    await owner.page.goto(pathOf(proofMail.button));
    await expect(owner.page.getByRole('heading', { level: 2, name: 'Proof of payment' })).toBeVisible();
    await owner.page.waitForLoadState('networkidle');
    await expectClean(owner);
    await owner.close();

    // 3. The caretaker follows the same link (the mail goes to every member) and confirms.
    const caretakerMail = await waitForMail(request, 'caretaker@example.com', `sent proof of payment for ${PROPERTY}`, new RegExp(`/landlord/reservations/${id}$`));
    const caretaker = await loggedIn(browser, 'caretaker@example.com');
    await caretaker.page.goto(pathOf(caretakerMail.button));
    await caretaker.page.waitForLoadState('networkidle');
    const panel = caretaker.page.getByTestId('decision-panel');
    await expect(panel.getByRole('button', { name: 'Confirm reservation' })).toBeEnabled();
    await panel.getByRole('button', { name: 'Confirm reservation' }).click();
    await expect(caretaker.page.getByRole('status')).toContainText('Reservation confirmed.');
    await expect(caretaker.page.locator('[data-status="confirmed"]')).toBeVisible();
    await expectClean(caretaker);
    await caretaker.close();

    // The confirmation email; its button opens the hold page, confirmed.
    const confirmed = await waitForMail(request, 'renter2@example.com', `Confirmed: your place at ${PROPERTY}`, new RegExp(`/reservations/${id}$`));
    expect(confirmed.text).toContain('Your reservation is confirmed');
    expect(confirmed.text).toContain(PROPERTY);

    await page.goto(pathOf(confirmed.button));
    await expect(page.getByRole('heading', { name: 'Your reservation is confirmed' })).toBeVisible();
    await expect(page.locator('[data-status="confirmed"]')).toBeVisible();
    await page.waitForLoadState('networkidle');
    await expectClean(guest);

    // 4. Cleanup: the bed is free again for the next run.
    await cancelLeftovers(page);
    await guest.close();
});
