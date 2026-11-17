import { expect, test } from '@playwright/test';
import { collectConsoleErrors, expectNoAxeViolations, expectNoAxeViolationsWithoutScripts, logIn } from './support';

/**
 * Slice 5.1: the public listing page. The demo's "last bed" house (Santos Bedspace House, README
 * "Demo logins") has photos, amenities, house rules and two rooms. Read-only: these tests never reserve,
 * so they can run in both viewports next to the Phase 2 race (reserve.spec.ts).
 */

const LAST_BED_LISTING = '/listings/manila/santos-bedspace-house';

test('home → a featured listing: its page has the rooms and a reserve form, and passes axe', async ({ page }, testInfo) => {
    const errors = await collectConsoleErrors(page);

    await page.goto('/');
    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('heading', { level: 2, name: 'New places' })).toBeVisible();
    const cards = page.locator('article.listing-card');
    await expect(cards).toHaveCount(6);
    await expect(page.getByRole('link', { name: 'Browse all' })).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('home.png'), fullPage: true });
    expect(errors).toEqual([]);
    await expectNoAxeViolations(page);
    errors.length = 0;

    // The first card's link goes to its listing (a client-side visit, so the head tags are rewritten, not doubled).
    const first = cards.first();
    const name = (await first.getByRole('heading', { level: 3 }).textContent()) ?? '';
    await first.getByRole('link', { name }).click();
    await expect(page).toHaveURL(/\/listings\/[a-z0-9-]+\/[a-z0-9-]+/);
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Open in Maps (opens Google Maps in a new tab)' })).toHaveAttribute('href', /^https:\/\/www\.google\.com\/maps\/search\/\?api=1&query=/);
    await expect(page.locator('form[action$="/reservations"]').first()).toBeVisible();
    await expect(page.locator('head title')).toHaveCount(1);
    await expect(page.locator('head link[rel="canonical"]')).toHaveCount(1);
    await expect(page.locator('head meta[property="og:title"]')).toHaveCount(1);
    await expect(page.locator('head script[type="application/ld+json"]')).toHaveCount(1);
    expect(errors).toEqual([]);
    await expectNoAxeViolations(page);
});

test('"Browse all" lists the listings, and paging works', async ({ page }) => {
    const errors = await collectConsoleErrors(page);

    await page.goto('/');
    await page.waitForLoadState('networkidle');
    await page.getByRole('link', { name: 'Browse all' }).click();
    await expect(page).toHaveURL(/\/listings$/);
    await expect(page.getByRole('heading', { level: 1, name: 'All listings' })).toBeVisible();
    await expect(page.locator('article.listing-card')).toHaveCount(12);
    expect(errors).toEqual([]);
    await expectNoAxeViolations(page);
});

test('the last-bed listing: gallery, details, rooms and the sticky bar on phones', async ({ page }, testInfo) => {
    const errors = await collectConsoleErrors(page);
    const phone = (page.viewportSize()?.width ?? 1280) < 768;

    await page.goto(LAST_BED_LISTING);
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveTitle(/^Santos Bedspace House · Rooms from ₱[\d,]+ in .*\| ReserveVision$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Santos Bedspace House' })).toBeVisible();
    await expect(page.getByText('Sampaloc').first()).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Amenities' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'House rules and refunds' })).toBeVisible();

    // The cover loads at once, every other photo lazily; each has alt text.
    const images = page.locator('.gallery img');
    expect(await images.count()).toBeGreaterThanOrEqual(3);
    await expect(images.first()).toHaveAttribute('loading', 'eager');
    await expect(images.first()).toHaveAttribute('fetchpriority', 'high');
    for (const [index, image] of (await images.all()).entries()) {
        await expect(image).toHaveAttribute('alt', /.+/);
        await expect(image).toHaveAttribute('width', /\d+/);
        if (index > 0) {
            await expect(image).toHaveAttribute('loading', 'lazy');
        }
    }

    // The two rooms the demo promises (README): the last-bed room is room 1.
    const room = page.locator('#room-1');
    await expect(room.getByRole('heading', { level: 3, name: 'Last Bed Room' })).toBeVisible();
    await expect(room.getByText(/\d of 4 beds free/)).toBeVisible();
    await expect(room.getByRole('button', { name: 'Reserve Last Bed Room' })).toBeVisible();
    await expect(page.getByLabel('Move-in date')).toBeVisible();

    const bar = page.locator('.sticky-reserve-bar');
    if (phone) {
        await expect(bar).toBeVisible();
        await page.screenshot({ path: testInfo.outputPath('listing-top.png') });
        await bar.getByRole('link', { name: 'Choose a room' }).click();
        await expect(page).toHaveURL(/#rooms$/);
        await page.screenshot({ path: testInfo.outputPath('listing-rooms.png') });
    } else {
        await expect(bar).toBeHidden();
    }

    // The old room URL lands on the room's anchor.
    await page.goto('/rooms/1?move_in=2031-01-01&months=3');
    await expect(page).toHaveURL(/\/listings\/manila\/santos-bedspace-house\?.*#room-1$/);
    const landed = new URL(page.url());
    expect(landed.searchParams.get('move_in')).toBe('2031-01-01');
    expect(landed.searchParams.get('months')).toBe('3');

    await page.goto(LAST_BED_LISTING);
    await page.waitForLoadState('networkidle');
    expect(errors).toEqual([]);
    await expectNoAxeViolations(page);
});

test('the last-bed listing shows the real, public-domain photos, each loaded and described (slice 7.1)', async ({ page }) => {
    const errors = await collectConsoleErrors(page);

    await page.goto(LAST_BED_LISTING);
    await page.waitForLoadState('networkidle');

    const images = page.locator('.gallery img');
    const count = await images.count();
    expect(count).toBeGreaterThanOrEqual(3);
    expect(count).toBeLessThanOrEqual(6);

    // Scroll each into view (the later ones are lazy), then check the browser really decoded a picture from our own media route.
    const alts: string[] = [];
    for (const image of await images.all()) {
        await image.scrollIntoViewIfNeeded();
        await expect.poll(() => image.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
        await expect(image).toHaveAttribute('src', /^\/media\/photos\/properties\/.+\.webp$/);

        const alt = (await image.getAttribute('alt')) ?? '';
        expect(alt.length).toBeGreaterThanOrEqual(20); // a description of the photo, not the old "Front of <name>"
        expect(alt).not.toMatch(/^Front of /);
        alts.push(alt);
    }
    expect(new Set(alts).size).toBe(alts.length);

    expect(errors).toEqual([]);
    await expectNoAxeViolations(page);
});

test('another place, unknown listings and old links', async ({ page, request }) => {
    expect((await request.get('/listings/manila/not-a-house')).status()).toBe(404);
    expect((await request.get('/listings/atlantis/santos-bedspace-house')).status()).toBe(404);
    expect((await request.get('/rooms/999999')).status()).toBe(404);

    await page.goto('/sitemap.xml');
    await expect(page.locator('body')).toContainText('santos-bedspace-house');
});

/**
 * T11 in a browser: JavaScript switched off, so only what the server sent. The page must say what
 * it is, cost what it costs, and still work as a form (the reserve button is a plain post).
 */
test.describe('without JavaScript (T11)', () => {
    test.use({ javaScriptEnabled: false });

    test('the listing is complete, has the sharing tags, passes axe and reserves with a plain form post', async ({ page, browser, baseURL }) => {
        test.setTimeout(90_000); // four browser contexts
        await page.goto(LAST_BED_LISTING);

        await expect(page).toHaveTitle(/^Santos Bedspace House · Rooms from ₱[\d,]+ in .*\| ReserveVision$/);
        await expect(page.getByRole('heading', { level: 1, name: 'Santos Bedspace House' })).toBeVisible();
        await expect(page.getByText(/From\s*₱[\d,]+\s*\/ month/).first()).toBeVisible();
        await expect(page.getByText('Sampaloc').first()).toBeVisible();
        await expect(page.getByText('Manila').first()).toBeVisible();
        const room = page.locator('#room-1');
        await expect(room.getByRole('heading', { name: 'Last Bed Room' })).toBeVisible();
        await expect(room.getByText(/₱[\d,]+\s*\/ month/)).toBeVisible();
        await expect(room.getByText(/\d of 4 beds free/)).toBeVisible();
        await expect(room.getByRole('button', { name: 'Reserve Last Bed Room' })).toBeVisible();
        await expect(page.locator('.gallery img').first()).toBeVisible();

        await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', new RegExp(`^${baseURL}/listings/manila/santos-bedspace-house$`.replace(/\./g, '\\.')));
        await expect(page.locator('meta[property="og:title"]')).toHaveAttribute('content', /Santos Bedspace House/);
        await expect(page.locator('meta[property="og:image"]')).toHaveAttribute('content', /^https?:\/\/.*-full\.webp$/);
        await expect(page.locator('meta[property="og:locale"]')).toHaveAttribute('content', 'en_PH');
        await expect(page.locator('meta[name="twitter:card"]')).toHaveAttribute('content', 'summary_large_image');
        const jsonLd = JSON.parse((await page.locator('script[type="application/ld+json"]').textContent()) ?? '{}');
        expect(jsonLd['@context']).toBe('https://schema.org');
        expect(jsonLd.offers.length).toBeGreaterThanOrEqual(1);

        await expectNoAxeViolationsWithoutScripts(page);

        // A plain form post, logged in (the session comes from a context that could run the login page's JavaScript):
        // a move-in far outside the window is refused by the server, nothing is created, and the message is on the page.
        const loggedIn = await browser.newContext({ baseURL, javaScriptEnabled: true });
        const loginPage = await loggedIn.newPage();
        await loginPage.goto('/login');
        await loginPage.waitForLoadState('networkidle');
        await logIn(loginPage, 'renter2@example.com', 'password');
        await expect(loginPage).toHaveURL(/\/$/);
        const state = await loggedIn.storageState();
        await loggedIn.close();

        const plain = await browser.newContext({ baseURL, javaScriptEnabled: false, storageState: state });
        const plainPage = await plain.newPage();
        const farOff = new Date(Date.now() + 400 * 86_400_000).toISOString().slice(0, 10);
        await plainPage.goto(`${LAST_BED_LISTING}?move_in=${farOff}&months=6`);
        await plainPage.getByRole('button', { name: 'Reserve Last Bed Room' }).click();
        await expect(plainPage).toHaveURL(new RegExp(`${LAST_BED_LISTING}\\?move_in=${farOff}`));
        await expect(plainPage.getByRole('alert')).toContainText(/move-in date/i);
        await plain.close();
    });
});
