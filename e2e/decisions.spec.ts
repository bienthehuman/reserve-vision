import { expect, test, type Browser, type Page } from '@playwright/test';
import path from 'node:path';
import { collectConsoleErrors, expectNoAxeViolations, logIn } from './support';

/**
 * Slice 6.1 demo, phone viewport, against both stacks. renter1 on the demo owner's Magno Rooms for Rent (README, "Demo
 * logins"), on a different stay from the Phase 5 demo, which uses renter2 there:
 *
 * 1. renter1 reserves a room and sends a proof.
 * 2. The owner asks for a new proof, with a reason.
 * 3. renter1 sees the reason and a new countdown, and sends a new proof.
 * 4. The caretaker confirms it.
 * 5. The owner undoes the confirmation, and confirms again.
 * 6. renter1 sees the reservation confirmed.
 * 7. The owner withdraws it and records the refund; renter1 sees both. This frees the bed again, so reruns on one stack
 *    keep finding one (the seeded stays leave few free).
 *
 * Zero axe violations on both pages in every state shown, the reason dialogs included. renter1 never has more than one open
 * reservation here, so the Phase 2 demo (renter1 holds the last bed) is unaffected. A run that died halfway leaves an open
 * reservation at Magno; the next run cancels it first (one open reservation per property).
 */

const PHONE = { viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true };
const RECEIPT = path.join('e2e', 'fixtures', 'receipt.jpg');
const LISTING = '/listings/makati/magno-rooms-for-rent';
const PROPERTY = 'Magno Rooms for Rent';
const REASON = 'The amount on the receipt is cut off. Please send the whole screenshot.';

/** A day `days` from today in Manila, "YYYY-MM-DD". Not the Phase 5 demo's date, so the two never compete for a bed. */
function manilaDate(days: number): string {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());
    const date = new Date(`${today}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

const MOVE_IN = manilaDate(80);
const WITHDRAW_REASON = 'A pipe burst in the room. We are very sorry.';

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

/** Cancels renter1's current reservations at Magno left by a run that died halfway (never the last-bed hold elsewhere). */
async function cancelLeftovers(page: Page): Promise<void> {
    await page.goto('/reservations');
    const links = await page
        .locator('section[aria-labelledby="current-title"] li', { hasText: PROPERTY })
        .locator('h3 a')
        .evaluateAll((anchors) => anchors.map((a) => a.getAttribute('href') ?? ''));

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

async function sendProof(page: Page, reference: string): Promise<void> {
    await page.getByLabel('Screenshot, photo or PDF of the receipt').setInputFiles(RECEIPT);
    await page.getByLabel('Reference number').fill(reference);
    await page.getByRole('button', { name: 'Send proof' }).click();
    await expect(page.getByRole('status')).toContainText('Proof sent.');
    await expect(page.locator('[data-status="proof_uploaded"]')).toHaveText('Awaiting review');
}

test('Phase 6 decisions: reject a proof, re-upload, the caretaker confirms, the owner undoes, confirms again and withdraws', async ({ browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'phone-360', 'one run per stack: each run takes a bed for the stay');
    test.setTimeout(150_000);

    // 1. renter1 reserves and sends a proof.
    const renter = await loggedIn(browser, 'renter1@example.com');
    await cancelLeftovers(renter.page);
    renter.errors.length = 0;
    await renter.page.goto(`${LISTING}?move_in=${MOVE_IN}&months=1#rooms`);
    await renter.page.waitForLoadState('networkidle');
    const free = await renter.page.locator('[data-testid^="free-spaces-"]').evaluateAll((nodes) =>
        nodes
            .filter((node) => /^The room is free|^[1-9]\d* of \d+ beds? free/.test(node.textContent ?? ''))
            .map((node) => node.getAttribute('data-testid')?.replace('free-spaces-', '') ?? ''),
    );
    expect(free, 'a room with a free place for the stay').not.toEqual([]);
    await renter.page.locator(`#room-${free[0]}`).getByRole('button', { name: /^Reserve / }).click();
    await expect(renter.page).toHaveURL(/\/reservations\/\d+$/);
    const id = renter.page.url().split('/').pop() ?? '';
    await renter.page.waitForLoadState('networkidle');
    await sendProof(renter.page, 'E2E-6100');

    // 2. The owner asks for a new proof.
    const owner = await loggedIn(browser, 'owner@example.com');
    await owner.page.goto(`/landlord/reservations/${id}`);
    const panel = owner.page.getByTestId('decision-panel');
    await expect(panel.getByRole('heading', { name: 'What to do next' })).toBeVisible();
    await expect(panel.getByRole('button', { name: 'Confirm reservation' })).toBeEnabled();
    await expect(owner.page.getByText('Hold ended')).toHaveCount(0); // the 5.3 backlog item: no hold line once a proof is in
    await expectClean(owner);

    await panel.getByRole('button', { name: 'Ask for a new proof' }).click();
    const dialog = owner.page.getByRole('dialog');
    await expect(dialog).toContainText('Ask for a new proof?');
    await dialog.getByRole('button', { name: 'Ask for a new proof' }).click();
    await expect(dialog.getByText('Write a reason. The renter will see it.')).toBeVisible(); // required, checked before sending
    await dialog.getByLabel("What's wrong with this proof?").fill(REASON);
    await expectNoAxeViolations(owner.page);
    owner.errors.length = 0;
    await dialog.getByRole('button', { name: 'Ask for a new proof' }).click();
    await expect(owner.page.getByRole('status')).toContainText('Proof sent back.');
    await expect(owner.page.locator('[data-status="held"]')).toBeVisible();
    await expect(owner.page.getByTestId('decision-panel')).toHaveCount(0);
    await expectClean(owner);

    // 3. renter1 sees why, and sends a new proof.
    await renter.page.reload();
    await expect(renter.page.getByRole('heading', { name: 'Send a new proof of payment' })).toBeVisible();
    await expect(renter.page.getByTestId('rejection-reason')).toHaveText(REASON);
    await expect(renter.page.getByTestId('hold-countdown')).toContainText('Your hold ends');
    await expect(renter.page.getByText('Not accepted')).toBeVisible();
    await expectClean(renter);
    await sendProof(renter.page, 'E2E-6101');
    await expectClean(renter);

    // 4. The caretaker confirms.
    const caretaker = await loggedIn(browser, 'caretaker@example.com');
    await caretaker.page.goto(`/landlord/reservations/${id}`);
    const caretakerPanel = caretaker.page.getByTestId('decision-panel');
    await expect(caretakerPanel).toContainText('You can ask for a new proof once more.');
    await expectClean(caretaker);
    await caretakerPanel.getByRole('button', { name: 'Confirm reservation' }).click();
    await expect(caretaker.page.getByRole('status')).toContainText('Reservation confirmed.');
    await expect(caretaker.page.locator('[data-status="confirmed"]')).toBeVisible();
    await expect(caretaker.page.getByTestId('decision')).toContainText('Confirmed by');
    await expect(caretakerPanel).toContainText(/Undo is possible until \d{1,2}:\d{2}\s[AP]M (today|tomorrow)\./);
    await expect(caretakerPanel).toContainText('Only an owner can withdraw a confirmed reservation.');
    await expect(caretakerPanel.getByRole('button', { name: 'Withdraw' })).toHaveCount(0);
    await expect(caretakerPanel.getByRole('button', { name: 'Mark as moved in' })).toBeDisabled();
    await expectClean(caretaker);
    await caretaker.close();

    // 5. The owner undoes it, and confirms again.
    await owner.page.reload();
    await owner.page.waitForLoadState('networkidle');
    await owner.page.getByTestId('decision-panel').getByRole('button', { name: 'Undo confirmation' }).click();
    await expect(owner.page.getByRole('status')).toContainText('Confirmation undone.');
    await expect(owner.page.locator('[data-status="proof_uploaded"]')).toBeVisible();
    await expectClean(owner);
    await owner.page.getByTestId('decision-panel').getByRole('button', { name: 'Confirm reservation' }).click();
    await expect(owner.page.getByRole('status')).toContainText('Reservation confirmed.');
    await expect(owner.page.getByTestId('decision-panel').getByRole('button', { name: 'Withdraw' })).toBeEnabled();
    await expectClean(owner);

    // 6. renter1 sees it confirmed.
    await renter.page.reload();
    await expect(renter.page.getByRole('heading', { name: 'Your reservation is confirmed' })).toBeVisible();
    await expect(renter.page.locator('[data-status="confirmed"]')).toBeVisible();
    await expectClean(renter);

    // 7. The owner withdraws it (irreversible: the dialog says so) and records the refund.
    await owner.page.getByTestId('decision-panel').getByRole('button', { name: 'Withdraw' }).click();
    const withdraw = owner.page.getByRole('dialog');
    await expect(withdraw).toContainText("You can't undo this.");
    await withdraw.getByLabel("Why can't you keep this booking?").fill(WITHDRAW_REASON);
    await expectNoAxeViolations(owner.page);
    owner.errors.length = 0;
    await withdraw.getByRole('button', { name: 'Withdraw reservation' }).click();
    await expect(owner.page.getByRole('status')).toContainText('Reservation withdrawn.');
    await expect(owner.page.locator('[data-status="cancelled"]')).toBeVisible();
    const refund = owner.page.getByTestId('refund-card');
    await expect(refund.getByTestId('refund-status')).toHaveText('Not recorded yet');
    await expectClean(owner);
    await refund.getByLabel('Yes, refunded').check();
    await refund.getByLabel('Note').fill('Sent back by GCash.');
    await refund.getByRole('button', { name: 'Save refund record' }).click();
    await expect(owner.page.getByRole('status')).toContainText('Refund record saved.');
    await expect(refund.getByTestId('refund-status')).toHaveText('Refunded');
    await expectClean(owner);
    await owner.close();

    await renter.page.reload();
    await expect(renter.page.getByRole('heading', { name: /withdrew your reservation$/ })).toBeVisible();
    await expect(renter.page.getByTestId('decision-reason')).toHaveText(WITHDRAW_REASON);
    await expect(renter.page.getByTestId('refund-record')).toContainText('says the fee was refunded.');
    await expectClean(renter);
    await renter.page.goto('/reservations');
    await expect(renter.page.locator(`a[href="/reservations/${id}"]`)).toBeVisible();
    await expect(renter.page.locator('section[aria-labelledby="past-title"]')).toContainText('by the landlord');
    await expectClean(renter);
    await renter.close();
});
