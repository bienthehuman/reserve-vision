import { expect, test, type Browser, type Page } from '@playwright/test';
import { collectConsoleErrors, expectNoAxeViolations, logIn } from './support';

/**
 * Phase 2 demo (slice 2.5, through the listing page since 5.1): two renters want the seeded "last bed" (README,
 * "Demo logins"). renter1 reserves it on the listing page and sees the hold;
 * renter2 then tries the same room and dates and is told there's no space.
 *
 * Phone viewport only: the two projects run in parallel, and two runs racing
 * for the same bed would make each other fail.
 */

/** DemoSeeder's Santos Bedspace House, whose "Last Bed Room" is room 1 on a freshly seeded database. */
const lastBedListing = '/listings/manila/santos-bedspace-house';
const lastBedRoom = (page: Page) => page.locator('#room-1');

/** The 1st of next month in Manila: beds A-C are confirmed from that date, bed D is free. */
function firstOfNextMonthInManila(): string {
    const [year = 0, month = 0] = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit' })
        .format(new Date())
        .split('-')
        .map(Number);
    const next = new Date(Date.UTC(year, month, 1)); // `month` is 1-based here, so this is next month's 1st

    return next.toISOString().slice(0, 10);
}

async function loggedInPage(browser: Browser, email: string): Promise<{ page: Page; errors: string[]; close: () => Promise<void> }> {
    const context = await browser.newContext({ viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true });
    const page = await context.newPage();
    const errors = await collectConsoleErrors(page);

    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await logIn(page, email, 'password');
    await expect(page).toHaveURL(/\/$/);

    return { page, errors, close: () => context.close() };
}

/** Opens the last-bed house's listing page, hydrated. */
async function openLastBedListing(page: Page): Promise<void> {
    await page.goto(lastBedListing);
    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('heading', { level: 1, name: 'Santos Bedspace House' })).toBeVisible();
    await expect(lastBedRoom(page).getByRole('heading', { level: 3, name: 'Last Bed Room' })).toBeVisible();
}

/** Picks the stay and asks for the free beds (a plain form: the page reloads with the move-in in the link). */
async function chooseStay(page: Page, moveIn: string): Promise<void> {
    await page.getByLabel('Move-in date').fill(moveIn);
    await page.getByLabel("How long you'll stay").selectOption('6');
    await page.getByRole('button', { name: 'Check free beds' }).click();
    await expect(page).toHaveURL(new RegExp(`move_in=${moveIn}`));
}

test('Phase 2 demo: renter1 takes the last bed, renter2 is told it is gone', async ({ browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'phone-360', 'one run per stack: both projects would race for the same bed');

    const moveIn = firstOfNextMonthInManila();

    // renter1 reserves the last bed.
    const renter1 = await loggedInPage(browser, 'renter1@example.com');
    await openLastBedListing(renter1.page);

    const existingHold = lastBedRoom(renter1.page).getByRole('link', { name: 'View your hold' });
    if (await existingHold.isVisible()) {
        // A rerun on the same dev stack within 24 hours: renter1 already holds the bed.
        // CI always starts from a freshly seeded stack, so it takes the full path below.
        expect(process.env.CI, 'renter1 already holds the bed on a fresh CI stack').toBeFalsy();
        await existingHold.click();
    } else {
        await chooseStay(renter1.page, moveIn);
        await expect(renter1.page.getByTestId('free-spaces-1')).toContainText('1 of 4 beds free');
        expect(renter1.errors).toEqual([]);
        await expectNoAxeViolations(renter1.page);
        // axe's injected <style> is blocked by the CSP (CLAUDE.md quirks, 1.1), and the
        // hold page is the same document after an Inertia visit: start counting afresh.
        renter1.errors.length = 0;

        await lastBedRoom(renter1.page).getByRole('button', { name: 'Reserve Last Bed Room' }).click();
    }

    await expect(renter1.page).toHaveURL(/\/reservations\/\d+$/);
    await expect(renter1.page.getByRole('heading', { level: 1, name: 'Your hold' })).toBeVisible();
    await expect(renter1.page.getByText('On hold')).toBeVisible();
    await expect(renter1.page.getByTestId('space-label')).toHaveText('Bed D');
    await expect(renter1.page.getByText('Hold ends', { exact: true })).toBeVisible();
    expect(renter1.errors).toEqual([]);
    await expectNoAxeViolations(renter1.page);
    await renter1.close();

    // renter2 tries the same room and dates.
    const renter2 = await loggedInPage(browser, 'renter2@example.com');
    await openLastBedListing(renter2.page);
    await chooseStay(renter2.page, moveIn);
    await expect(renter2.page.getByTestId('free-spaces-1')).toContainText('0 of 4 beds free');

    await lastBedRoom(renter2.page).getByRole('button', { name: 'Reserve Last Bed Room' }).click();

    await expect(renter2.page.getByRole('alert')).toHaveText(/no free space for those dates/);
    await expect(renter2.page).toHaveURL(new RegExp(`${lastBedListing}\\?`));
    expect(renter2.errors).toEqual([]);
    await expectNoAxeViolations(renter2.page);
    await renter2.close();
});
