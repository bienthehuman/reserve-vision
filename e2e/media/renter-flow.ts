/**
 * Records the README's renter-flow GIF (slice 7.3). Not a test: `npm run e2e` never runs it (no `.spec` in the name).
 * Run it through `bin/record-media` (or `make media`), which converts the videos with ffmpeg.
 *
 * On a 390 × 844 phone, against a stack with the demo data (the production stack by default):
 *   1. renter: home → search Manila → Santos Bedspace House → Reserve → log in as renter2 → Reserve → hold page
 *      → upload a proof → "Awaiting review"
 *   2. owner: the reservation → Confirm
 *   3. renter: the hold page again → "Confirmed"
 *
 * Each part is its own browser context, so its own video. The script writes the videos and a `cuts.json` (the seconds of
 * each video to keep) to the output directory. renter2's open reservations are cancelled before and after, outside the
 * recording, so it can run again.
 *
 * Environment: MEDIA_BASE_URL (default http://localhost:8280), MEDIA_OUT (default test-results/media).
 */
import { chromium, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { mkdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';

const BASE_URL = process.env.MEDIA_BASE_URL ?? 'http://localhost:8280';
const OUT = process.env.MEDIA_OUT ?? path.join('test-results', 'media');
const RECEIPT = path.join('e2e', 'fixtures', 'receipt.jpg');
const SIZE = { width: 390, height: 844 };
const LISTING = '/listings/manila/santos-bedspace-house';

/** A reading pause, so the viewer can follow. */
const BEAT = 900;

function manilaDate(days: number): string {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());
    const date = new Date(`${today}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

/** Far enough out that a bed is free, inside the 90-day booking window. */
const MOVE_IN = manilaDate(60);

interface Part {
    name: string;
    context: BrowserContext;
    page: Page;
    /** performance.now() when the context (and so its video) started. */
    t0: number;
    keep: [number, number][];
    mark: number | null;
}

/** A dot where the finger taps: a GIF has no cursor. Set through the DOM, not markup (bypassCSP is on for this context only). */
const TAP_SCRIPT = `
addEventListener('pointerdown', (event) => {
    const dot = document.createElement('div');
    Object.assign(dot.style, {
        position: 'fixed', left: (event.clientX - 18) + 'px', top: (event.clientY - 18) + 'px', width: '36px', height: '36px',
        borderRadius: '50%', background: 'rgba(91, 61, 140, 0.35)', border: '2px solid rgba(91, 61, 140, 0.8)',
        pointerEvents: 'none', zIndex: '2147483647', transition: 'opacity 450ms ease-out, transform 450ms ease-out',
    });
    document.documentElement.appendChild(dot);
    requestAnimationFrame(() => requestAnimationFrame(() => { dot.style.opacity = '0'; dot.style.transform = 'scale(1.6)'; }));
    setTimeout(() => dot.remove(), 600);
}, true);
`;

async function startPart(browser: Browser, name: string, storageState?: Awaited<ReturnType<BrowserContext['storageState']>>): Promise<Part> {
    const context = await browser.newContext({
        baseURL: BASE_URL,
        viewport: SIZE,
        screen: SIZE,
        deviceScaleFactor: 1,
        isMobile: true,
        hasTouch: true,
        bypassCSP: true,
        storageState,
        recordVideo: { dir: path.join(OUT, 'raw', name), size: SIZE },
    });
    await context.addInitScript(TAP_SCRIPT);
    const t0 = performance.now();
    const page = await context.newPage();

    return { name, context, page, t0, keep: [], mark: null };
}

function seconds(part: Part): number {
    return (performance.now() - part.t0) / 1000;
}

/** Starts a stretch of video to keep. */
function roll(part: Part): void {
    part.mark = seconds(part);
}

/** Ends the stretch; what happens until the next roll() is cut. */
function cut(part: Part): void {
    if (part.mark !== null) {
        part.keep.push([Number(part.mark.toFixed(2)), Number(seconds(part).toFixed(2))]);
    }
    part.mark = null;
}

async function settle(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle');
}

/** Scrolls the element to the middle of the screen (a jump, whatever the page CSS says: a smooth scroll over photos costs a full GIF frame per step), then taps it. */
async function tap(page: Page, locator: ReturnType<Page['locator']>): Promise<void> {
    await locator.evaluate((element) => element.scrollIntoView({ behavior: 'instant', block: 'center' }));
    await page.waitForTimeout(700);
    await locator.tap();
}

/** Reads the confirmation toast, closes it (it sits over the status badge), and shows the top of the page. */
async function dismissToast(page: Page): Promise<void> {
    await page.waitForTimeout(BEAT * 1.5);
    await page.getByRole('status').getByRole('button', { name: 'Dismiss this message' }).tap();
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
}

async function typeSlowly(locator: ReturnType<Page['locator']>, text: string): Promise<void> {
    await locator.tap();
    await locator.pressSequentially(text, { delay: 45 });
}

async function logIn(page: Page, email: string): Promise<void> {
    await typeSlowly(page.getByLabel('Email'), email);
    await typeSlowly(page.getByLabel('Password', { exact: true }), 'password');
    await page.getByRole('button', { name: 'Log in' }).tap();
}

/** Logs in without recording. */
async function loggedIn(browser: Browser, email: string): Promise<BrowserContext> {
    const context = await browser.newContext({ baseURL: BASE_URL, viewport: SIZE, isMobile: true, hasTouch: true });
    const page = await context.newPage();
    await page.goto('/login');
    await settle(page);
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByRole('button', { name: /account menu$/ })).toBeVisible();

    return context;
}

/** Cancels every reservation renter2 can still cancel (as T12 does), so the flow starts from nothing. */
async function cancelOpenReservations(browser: Browser): Promise<void> {
    const context = await loggedIn(browser, 'renter2@example.com');
    const page = await context.newPage();
    await page.goto('/reservations');
    const links = await page.locator('section[aria-labelledby="current-title"] h3 a').evaluateAll((anchors) => anchors.map((a) => a.getAttribute('href') ?? ''));

    for (const href of links) {
        await page.goto(href);
        await settle(page);
        const cancel = page.getByRole('button', { name: 'Cancel reservation' });
        if (await cancel.isVisible()) {
            await cancel.click();
            await page.getByRole('dialog').getByRole('button', { name: 'Cancel reservation' }).click();
            await expect(page.locator('[data-status="cancelled"]').first()).toBeVisible();
        }
    }
    await context.close();
}

async function renterReserves(browser: Browser): Promise<{ part: Part; reservation: string }> {
    const part = await startPart(browser, '1-renter');
    const { page } = part;

    await page.goto('/');
    await settle(page);
    roll(part);
    await page.waitForTimeout(BEAT * 1.5);
    await page.getByLabel('Where do you want to live?').selectOption({ label: 'Manila' });
    await page.waitForTimeout(BEAT / 2);
    await tap(page, page.getByRole('button', { name: 'Search', exact: true }));
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await settle(page);
    await page.waitForTimeout(BEAT);

    const card = page.locator(`a[href^="${LISTING}"]`).first();
    await tap(page, card);
    await expect(page.getByRole('heading', { level: 1, name: 'Santos Bedspace House' })).toBeVisible();
    await settle(page);
    await page.waitForTimeout(BEAT * 1.5);

    // The stay: a move-in date far enough out that a bed is free.
    cut(part);
    await page.goto(`${LISTING}?move_in=${MOVE_IN}&months=1#rooms`);
    await settle(page);
    const free = await page.locator('[data-testid^="free-spaces-"]').evaluateAll((nodes) =>
        nodes
            .filter((node) => /^The room is free|^[1-9]\d* of \d+ beds? free/.test(node.textContent ?? ''))
            .map((node) => node.getAttribute('data-testid')?.replace('free-spaces-', '') ?? ''),
    );
    if (free.length === 0) {
        throw new Error(`No free room at ${LISTING} from ${MOVE_IN}: reseed the stack.`);
    }
    const room = page.locator(`#room-${free[0]}`);
    await room.evaluate((element) => element.scrollIntoView({ behavior: 'instant', block: 'start' }));
    await page.waitForTimeout(300);
    roll(part);
    await page.waitForTimeout(BEAT * 1.5);
    await tap(page, room.getByRole('button', { name: /^Reserve / }));

    // A guest is sent to log in, and comes back to the same room and stay.
    await expect(page).toHaveURL(/\/login$/);
    await settle(page);
    await page.waitForTimeout(BEAT / 2);
    await logIn(page, 'renter2@example.com');
    await page.waitForURL(/santos-bedspace-house/);
    await settle(page);
    await page.waitForTimeout(BEAT);
    await tap(page, page.locator(`#room-${free[0]}`).getByRole('button', { name: /^Reserve / }));

    await expect(page).toHaveURL(/\/reservations\/\d+$/);
    const reservation = page.url().split('/').pop() ?? '';
    await expect(page.getByRole('heading', { level: 1, name: 'Your hold' })).toBeVisible();
    await settle(page);
    await page.waitForTimeout(BEAT * 2);

    // The proof of payment.
    const file = page.getByLabel('Screenshot, photo or PDF of the receipt');
    await file.evaluate((element) => element.scrollIntoView({ behavior: 'instant', block: 'center' }));
    await page.waitForTimeout(BEAT);
    await file.setInputFiles(RECEIPT);
    await typeSlowly(page.getByLabel('Reference number'), '7311 482 905');
    await page.waitForTimeout(BEAT / 2);
    await tap(page, page.getByRole('button', { name: 'Send proof' }));
    await expect(page.locator('[data-status="proof_uploaded"]').first()).toHaveText('Awaiting review');
    await dismissToast(page);
    await page.waitForTimeout(BEAT * 2);
    cut(part);

    return { part, reservation };
}

async function ownerConfirms(browser: Browser, reservation: string): Promise<Part> {
    const login = await loggedIn(browser, 'owner@example.com');
    const state = await login.storageState();
    await login.close();

    const part = await startPart(browser, '2-owner', state);
    const { page } = part;
    await page.goto(`/landlord/reservations/${reservation}`);
    await settle(page);
    roll(part);
    await page.waitForTimeout(BEAT * 1.5);
    const proof = page.getByRole('heading', { level: 2, name: 'Proof of payment' });
    await proof.evaluate((element) => element.scrollIntoView({ behavior: 'instant', block: 'start' }));
    await page.waitForTimeout(BEAT * 1.5);
    const confirm = page.getByTestId('decision-panel').getByRole('button', { name: 'Confirm reservation' });
    await tap(page, confirm);
    await expect(page.getByRole('status')).toContainText('Reservation confirmed.');
    await expect(page.locator('[data-status="confirmed"]').first()).toBeVisible();
    await dismissToast(page);
    await page.waitForTimeout(BEAT * 1.5);
    cut(part);

    return part;
}

async function renterSeesConfirmed(browser: Browser, reservation: string, renter: Part): Promise<Part> {
    const part = await startPart(browser, '3-renter', await renter.context.storageState());
    const { page } = part;
    await page.goto(`/reservations/${reservation}`);
    await expect(page.getByRole('heading', { name: 'Your reservation is confirmed' })).toBeVisible();
    await settle(page);
    roll(part);
    await page.waitForTimeout(BEAT * 3);
    cut(part);

    return part;
}

async function main(): Promise<void> {
    await rm(OUT, { recursive: true, force: true });
    await mkdir(OUT, { recursive: true });

    const browser = await chromium.launch();
    try {
        await cancelOpenReservations(browser);

        const { part: renter, reservation } = await renterReserves(browser);
        const owner = await ownerConfirms(browser, reservation);
        const confirmed = await renterSeesConfirmed(browser, reservation, renter);

        const parts = [renter, owner, confirmed];
        const cuts = [];
        for (const part of parts) {
            await part.context.close(); // writes the video
            cuts.push({ name: part.name, video: await part.page.video()?.path(), keep: part.keep });
        }
        await writeFile(path.join(OUT, 'cuts.json'), `${JSON.stringify(cuts, null, 2)}\n`);
        console.log(`Recorded ${parts.length} parts for reservation ${reservation}: ${path.join(OUT, 'cuts.json')}`);

        await cancelOpenReservations(browser);
    } finally {
        await browser.close();
    }
}

await main();
