import { expect, test, type Browser, type Page } from '@playwright/test';
import path from 'node:path';
import { collectConsoleErrors, expectNoAxeViolations, logIn } from './support';

/**
 * Phase 5 demo (slice 5.3), phone viewport, against both stacks (on production the proof goes
 * through MinIO). All on the demo owner's listings (README, "Demo logins"), never the last-bed
 * house, which the Phase 2 demo races for.
 *
 * 1. renter2 reserves a room at Magno Rooms for Rent and cancels it.
 * 2. A guest searches for Baguio, opens Villanueva Dormitory, presses Reserve, logs in as renter2,
 *    comes back to the same room and stay, reserves, and lands on the hold page.
 * 3. renter2 uploads a proof and sees "Awaiting review".
 * 4. The owner opens the reservation and sees the proof (through its signed URL).
 * 5. renter1 opens renter2's proof link and is denied, and so is the owner's signed URL in renter1's hands.
 *
 * The cancel comes first so renter2 never has two open reservations at once: the Phase 2 demo, running
 * in parallel, expects renter2's attempt at the last bed to fail for want of a bed, not for the limit.
 * Every run starts by cancelling what renter2 still has open from an earlier run (dev stack reruns).
 */

const PHONE = { viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true };
const RECEIPT = path.join('e2e', 'fixtures', 'receipt.jpg'); // DemoReceiptPainter's placeholder 4

/** A day `days` from today in Manila, "YYYY-MM-DD". */
function manilaDate(days: number): string {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());
    const date = new Date(`${today}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

/** Near the end of the 90-day window, where the seeded stays leave most beds free. */
const MOVE_IN = manilaDate(85);

async function newPhonePage(browser: Browser) {
    const context = await browser.newContext(PHONE);
    const page = await context.newPage();
    const errors = await collectConsoleErrors(page);

    return { page, errors, close: () => context.close() };
}

async function logInAs(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await logIn(page, email, 'password');
    await expect(page.getByRole('button', { name: /account menu$/ })).toBeVisible();
}

/** Cancels every current reservation renter2 can still cancel (left by an earlier run on the same stack). */
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
            await expect(page.getByTestId('space-label')).toBeVisible();
            await expect(page.locator('[data-status="cancelled"]').first()).toBeVisible();
        }
    }
}

/** Chooses the stay on a listing page (a plain GET form), then returns the first room that still has a free place. */
async function chooseStayAndRoom(page: Page) {
    await page.getByLabel('Move-in date').fill(MOVE_IN);
    await page.getByLabel("How long you'll stay").selectOption('1');
    await page.getByRole('button', { name: 'Check free beds' }).click();
    await expect(page).toHaveURL(new RegExp(`move_in=${MOVE_IN}`));
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-testid^="free-spaces-"]').first()).toContainText(' for ');

    // The first room with a free place for the stay: "The room is free", or "N of M beds free" with N > 0.
    const free = await page.locator('[data-testid^="free-spaces-"]').evaluateAll((nodes) =>
        nodes
            .filter((node) => /^The room is free|^[1-9]\d* of \d+ beds? free/.test(node.textContent ?? ''))
            .map((node) => node.getAttribute('data-testid')?.replace('free-spaces-', '') ?? ''),
    );
    expect(free, 'a room with a free place for the stay').not.toEqual([]);
    const room = page.locator(`#room-${free[0]}`);
    await expect(room.getByRole('button', { name: /^Reserve / })).toBeVisible();

    return room;
}

test('Phase 5 demo: reserve from a search, upload a proof, the owner sees it, nobody else can, and a cancel', async ({ browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'phone-360', 'one run per stack: renter2 has one hold per property');
    test.setTimeout(120_000);

    // 1. renter2 reserves a room at Magno Rooms for Rent and cancels it.
    const renter2 = await newPhonePage(browser);
    await logInAs(renter2.page, 'renter2@example.com');
    await cancelLeftovers(renter2.page);

    await renter2.page.goto('/listings/makati/magno-rooms-for-rent');
    await renter2.page.waitForLoadState('networkidle');
    const firstRoom = await chooseStayAndRoom(renter2.page);
    await firstRoom.getByRole('button', { name: /^Reserve / }).click();
    await expect(renter2.page).toHaveURL(/\/reservations\/\d+$/);
    await renter2.page.waitForLoadState('networkidle');

    await renter2.page.getByRole('button', { name: 'Cancel reservation' }).click();
    const dialog = renter2.page.getByRole('dialog');
    await expect(dialog).toContainText('Cancel this reservation?');
    await dialog.getByRole('button', { name: 'Cancel reservation' }).click();
    await expect(renter2.page.getByRole('status')).toContainText('Your reservation is cancelled.');
    await expect(renter2.page.locator('[data-status="cancelled"]')).toBeVisible();
    await expect(renter2.page.getByRole('button', { name: 'Cancel reservation' })).toHaveCount(0);
    await renter2.close();

    // 2. A guest searches, opens a listing, presses Reserve and logs in.
    const guest = await newPhonePage(browser);
    const page = guest.page;
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    await page.getByLabel('Where do you want to live?').selectOption({ label: 'Baguio' });
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    // Two Baguio landlords have a "Villanueva Dormitory"; the demo owner's is the one at /villanueva-dormitory.
    await page.locator('a[href^="/listings/baguio/villanueva-dormitory?"], a[href="/listings/baguio/villanueva-dormitory"]').first().click();
    await expect(page.getByRole('heading', { level: 1, name: 'Villanueva Dormitory' })).toBeVisible();
    await page.waitForLoadState('networkidle');

    const room = await chooseStayAndRoom(page);
    const roomId = (await room.getAttribute('id')) ?? '';
    await room.getByRole('button', { name: /^Reserve / }).click();

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('status')).toContainText('Log in or sign up to reserve.');
    await page.waitForLoadState('networkidle');
    await logIn(page, 'renter2@example.com', 'password');

    // Back on the listing, at the same room, with the same stay. The login answers with Inertia's `409` +
    // X-Inertia-Location (a full page load keeps the #room anchor), which Chrome logs as a failed resource.
    await page.waitForURL(/villanueva-dormitory/);
    expect(guest.errors.filter((error) => !error.includes('409 (Conflict)'))).toEqual([]);
    guest.errors.length = 0;
    // Same room and stay, whatever the order of the query (Inertia writes the page's URL with its own order once hydrated).
    await expect.poll(() => {
        const url = new URL(page.url());
        return [url.pathname, url.searchParams.get('move_in'), url.searchParams.get('months'), url.hash].join(' ');
    }).toBe(`/listings/baguio/villanueva-dormitory ${MOVE_IN} 1 #${roomId}`);
    await page.waitForLoadState('networkidle');
    await page.locator(`#${roomId}`).getByRole('button', { name: /^Reserve / }).click();

    // The hold page.
    await expect(page).toHaveURL(/\/reservations\/\d+$/);
    const reservationId = page.url().split('/').pop() ?? '';
    await expect(page.getByRole('heading', { level: 1, name: 'Your hold' })).toBeVisible();
    await expect(page.getByTestId('hold-countdown')).toContainText('Your hold ends');
    await expect(page.getByTestId('payment-instructions')).not.toBeEmpty();
    await page.waitForLoadState('networkidle');
    expect(guest.errors).toEqual([]);
    await expectNoAxeViolations(page);
    guest.errors.length = 0; // axe's <style> is blocked by the CSP (CLAUDE.md quirks, 1.1)

    // 3. The proof.
    await page.getByLabel('Screenshot, photo or PDF of the receipt').setInputFiles(RECEIPT);
    await page.getByLabel('Reference number').fill('DEMO-4444');
    await page.getByRole('button', { name: 'Send proof' }).click();
    await expect(page.getByRole('status')).toContainText('Proof sent.');
    await expect(page.locator('[data-status="proof_uploaded"]')).toHaveText('Awaiting review');
    await expect(page.getByRole('heading', { name: 'Proofs you sent' })).toBeVisible();
    const ownImage = page.getByRole('img', { name: /^Your proof of payment, sent/ });
    await expect(ownImage).toBeVisible();
    await expect.poll(() => ownImage.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
    const proofHref = (await page.getByRole('link', { name: /Open full size/ }).getAttribute('href')) ?? '';
    expect(proofHref).toMatch(/^\/proofs\/\d+$/);
    expect(guest.errors).toEqual([]);
    await expectNoAxeViolations(page);
    await guest.close();

    // 4. The owner opens the reservation and views the proof.
    const owner = await newPhonePage(browser);
    await logInAs(owner.page, 'owner@example.com');
    await owner.page.goto(`/landlord/reservations/${reservationId}`);
    await expect(owner.page.getByRole('heading', { level: 2, name: 'Proof of payment' })).toBeVisible();
    const proofImage = owner.page.getByRole('img', { name: /^Proof of payment from / });
    await expect(proofImage).toBeVisible();
    await expect.poll(() => proofImage.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
    await expect(owner.page.getByText('DEMO-4444')).toBeVisible();
    expect(owner.errors).toEqual([]);
    await expectNoAxeViolations(owner.page);

    const signed = await owner.page.request.get(proofHref, { maxRedirects: 0 });
    expect(signed.status()).toBe(302);
    const signedUrl = signed.headers()['location'] ?? '';
    expect(signedUrl).toContain('signature=');
    const file = await owner.page.request.get(signedUrl);
    expect(file.status()).toBe(200);
    expect(file.headers()['content-type']).toBe('image/jpeg');
    expect(file.headers()['content-security-policy']).toBe('sandbox');

    // 5. renter1 is denied: the proof link, and the owner's own signed URL.
    const renter1 = await newPhonePage(browser);
    await logInAs(renter1.page, 'renter1@example.com');
    expect((await renter1.page.request.get(proofHref, { maxRedirects: 0 })).status()).toBe(404);
    const stolen = await renter1.page.request.get(signedUrl);
    expect(stolen.status()).toBe(403);
    expect(stolen.headers()['content-type'] ?? '').not.toContain('image/');

    // My reservations lists the hold under Current; nothing on that page fails axe.
    await renter1.close();
    const renter2Again = await newPhonePage(browser);
    await logInAs(renter2Again.page, 'renter2@example.com');
    await renter2Again.page.goto('/reservations');
    await expect(renter2Again.page.getByRole('heading', { level: 1, name: 'My reservations' })).toBeVisible();
    await expect(renter2Again.page.locator('section[aria-labelledby="current-title"]')).toContainText('Villanueva Dormitory');
    await renter2Again.page.waitForLoadState('networkidle');
    expect(renter2Again.errors).toEqual([]);
    await expectNoAxeViolations(renter2Again.page);
    await renter2Again.close();
    await owner.close();
});
