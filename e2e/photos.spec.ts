import { expect, test } from '@playwright/test';
import path from 'node:path';
import { collectConsoleErrors, expectNoAxeViolations, logIn } from './support';

/**
 * Slice 4.2: the owner adds two photos to a new property at once, waits for the worker to resize
 * them (the page polls), makes the second the cover, describes it, sees a renamed text file refused,
 * and deletes a photo. Then a seeded listing: its demo photos load. Zero axe violations along the way.
 *
 * It needs the queue worker, and on the production stack the photos go to MinIO and come back
 * through Caddy at /media/photos. Like 4.1's test it adds a property to the demo owner's account on
 * each run, so it runs in one project only. Set SCREENSHOT_DIR to save the 360 px screenshots for the PR.
 *
 * The two fixtures are paintings by Database\Seeders\Demo\DemoPhotoPainter, scaled to 1000 × 750.
 */

test.describe.configure({ timeout: 120_000 });

/** `npm run e2e` runs from the repository root. */
const fixture = (name: string) => path.join('e2e', 'fixtures', name);

test('4.2: the owner uploads photos, waits for them to be ready, reorders, describes and deletes', async ({ browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'phone-360', 'Phone viewport only: one run adds one property');

    const context = await browser.newContext({ viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true });
    const page = await context.newPage();
    const errors = await collectConsoleErrors(page);
    const shot = async (name: string) => {
        if (process.env.SCREENSHOT_DIR) {
            await page.screenshot({ path: `${process.env.SCREENSHOT_DIR}/${name}.png`, fullPage: true });
        }
    };
    /** No console or CSP errors, then zero axe violations; axe's own <style> is a CSP error afterwards, so the list restarts. */
    const clean = async () => {
        expect(errors).toEqual([]);
        await expectNoAxeViolations(page);
        errors.length = 0;
    };
    /** Every photo on the page has really loaded: the URL works and the bytes are an image. */
    const expectPhotosLoaded = async (count: number) => {
        const images = page.locator('.photo-card img');
        await expect(images).toHaveCount(count);
        for (const image of await images.all()) {
            await image.scrollIntoViewIfNeeded();
            await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth)).toBe(480);
            expect(new URL((await image.getAttribute('src')) ?? '', page.url()).pathname).toMatch(/^\/media\/photos\/properties\/[0-9a-f-]{36}-thumb\.webp$/);
        }
    };

    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await logIn(page, 'owner@example.com', 'password');
    await expect(page).toHaveURL(/\/landlord$/);

    // A new draft property: no photos yet.
    const name = `E2E Photos ${Date.now()}`;
    await page.goto('/landlord/properties/create');
    await page.waitForLoadState('networkidle');
    await page.getByLabel('Property name', { exact: true }).fill(name);
    await page.getByLabel('Barangay', { exact: true }).fill('Loyola Heights');
    await page.getByLabel('City', { exact: true }).selectOption('quezon-city');
    await page.getByRole('button', { name: 'Save as a draft' }).click();
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
    await page.waitForLoadState('networkidle');
    await expect(page.getByText(`${name} has no photos yet.`)).toBeVisible();
    await expect(page.getByText('0 of 12')).toBeVisible();
    await shot('photos-empty');
    await clean();

    // Two photos at once. The worker resizes them; the page polls until both are ready.
    await page.getByLabel('Add photos').setInputFiles([fixture('bunks.jpg'), fixture('kitchen.jpg')]);
    await page.getByRole('button', { name: 'Upload photos' }).click();
    await expect(page.locator('.photo-card')).toHaveCount(2, { timeout: 30_000 });
    await expect(page.getByText('2 of 12')).toBeVisible();
    await expect(page.getByRole('img', { name: `Photo 1 of ${name}` })).toBeVisible({ timeout: 60_000 });
    await expect(page.getByRole('img', { name: `Photo 2 of ${name}` })).toBeVisible({ timeout: 60_000 });
    await expect(page.getByText(/Resizing photo/)).toHaveCount(0);
    await expectPhotosLoaded(2);
    await expect(page.locator('.photo-card').first().getByText('Cover')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Move up photo 1' })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Move down photo 2' })).toBeDisabled();
    await shot('photos-ready');
    await clean();

    // The kitchen (photo 2) becomes the cover.
    const kitchen = await page.getByRole('img', { name: `Photo 2 of ${name}` }).getAttribute('src');
    await page.getByRole('button', { name: 'Move up photo 2' }).click();
    await expect(page.getByText('The photo is now the cover.')).toBeVisible();
    await expect(page.locator('.photo-card').first().locator('img')).toHaveAttribute('src', kitchen ?? '');

    // Describe it.
    await page.getByRole('button', { name: 'Describe photo 1' }).click();
    await page.getByLabel('Description').fill('Shared kitchen with a dining table');
    await clean();
    await page.getByRole('button', { name: 'Save description' }).click();
    await expect(page.getByText('The description is saved.')).toBeVisible();
    await expect(page.getByRole('img', { name: 'Shared kitchen with a dining table' })).toBeVisible();

    // A text file renamed to .jpg is refused by its content, and nothing is added.
    await page.getByLabel('Add photos').setInputFiles({ name: 'bedroom.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('Just some notes, not a photo.') });
    await page.getByRole('button', { name: 'Upload photos' }).click();
    await expect(page.getByText('bedroom.jpg: This file is not a JPEG, PNG or WebP photo.')).toBeVisible();
    await expect(page.locator('.photo-card')).toHaveCount(2);
    await shot('photos-refused');
    await clean();

    // Delete the second photo, after confirming.
    await page.getByRole('button', { name: 'Delete photo 2' }).click();
    const dialog = page.getByRole('dialog', { name: 'Delete photo 2?' });
    await expect(dialog).toBeVisible();
    await clean();
    await dialog.getByRole('button', { name: 'Delete photo' }).click();
    await expect(page.getByText('The photo is deleted.')).toBeVisible();
    await expect(page.locator('.photo-card')).toHaveCount(1);
    await expect(page.getByText('1 of 12')).toBeVisible();
    await expect(dialog).toHaveCount(0);
    await expectPhotosLoaded(1);
    await clean();

    await context.close();
});

test('4.2: a seeded listing has ready photos, and a caretaker sees them without any button', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = await collectConsoleErrors(page);

    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await logIn(page, 'caretaker@example.com', 'password');
    await expect(page).toHaveURL(/\/landlord$/);

    await page.goto('/landlord/properties');
    await page.waitForLoadState('networkidle');
    const href = await page.getByRole('link', { name: 'Santos Bedspace House' }).first().getAttribute('href');
    await page.goto(href ?? '');
    await page.waitForLoadState('networkidle');

    const images = page.locator('.photo-card img');
    expect(await images.count()).toBeGreaterThanOrEqual(3);
    expect(await images.count()).toBeLessThanOrEqual(6);
    await expect(images.first()).toHaveAttribute('alt', 'Front of Santos Bedspace House');
    for (const image of await images.all()) {
        await image.scrollIntoViewIfNeeded();
        await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth)).toBe(480);
    }

    // The full size is there too, 1600 px wide.
    const full = (await images.first().getAttribute('src'))?.replace('-thumb.webp', '-full.webp') ?? '';
    const response = await page.request.get(full);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toBe('image/webp');

    await expect(page.getByRole('button', { name: /Move up|Delete|Describe/ })).toHaveCount(0);
    await expect(page.getByLabel('Add photos')).toHaveCount(0);

    expect(errors).toEqual([]);
    await expectNoAxeViolations(page);

    await context.close();
});
