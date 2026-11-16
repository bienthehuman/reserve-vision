import { expect, test, type APIRequestContext } from '@playwright/test';
import path from 'node:path';
import { collectConsoleErrors, expectNoAxeViolations, isProductionRun, logIn, mailpitUrl } from './support';

/**
 * The phase 4 demo (slice 4.3), on the phone viewport:
 *
 *  1. The owner creates a property with a shared room, uploads a photo, waits for it to be ready, sees the
 *     publish checklist complete, and publishes. The room page (slice 2.5) then opens.
 *  2. The owner invites a caretaker. The test reads the invitation from Mailpit, signs up through the link
 *     and lands on /landlord as a caretaker, whose pages are read-only.
 *  3. The owner removes the caretaker, whose next request is refused (403).
 *
 * Zero axe violations on every page along the way. It needs the queue worker (the photo and the email).
 *
 * Against the production stack (E2E_SKIP_DEV_PAGES=1) there is no Mailpit: only the email step is skipped, and
 * the caretaker signs up through E2E_INVITATION_URL, a link a CI step made with `php artisan landlord:invite`.
 *
 * It adds a property and a member to the demo owner's account on each run, so it runs in one project only.
 * Set SCREENSHOT_DIR to save the 360 px screenshots for the PR.
 */

test.describe.configure({ timeout: 180_000 });

const fixture = (name: string) => path.join('e2e', 'fixtures', name);
const phone = { viewport: { width: 360, height: 800 }, isMobile: true, hasTouch: true };

test('phase 4: publish a new property, invite a caretaker who joins by the emailed link, then remove them', async ({ browser, request }, testInfo) => {
    test.skip(testInfo.project.name !== 'phone-360', 'Phone viewport only: one run adds one property and one member');
    test.skip(isProductionRun && !process.env.E2E_INVITATION_URL, 'Against production, E2E_INVITATION_URL must hold a link from `php artisan landlord:invite`');

    const stamp = Date.now();
    const context = await browser.newContext(phone);
    const page = await context.newPage();
    const errors = await collectConsoleErrors(page);
    const shot = async (name: string, target = page) => {
        if (process.env.SCREENSHOT_DIR) {
            await target.screenshot({ path: `${process.env.SCREENSHOT_DIR}/${name}.png`, fullPage: true });
        }
    };
    /** No console or CSP errors, then zero axe violations; axe's own <style> is a CSP error afterwards, so the list restarts. */
    const clean = async (target = page, list = errors) => {
        expect(list).toEqual([]);
        await expectNoAxeViolations(target);
        list.length = 0;
    };

    // ── 1. A new property, from draft to published ───────────────────────────────────────────────
    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await logIn(page, 'owner@example.com', 'password');
    await expect(page).toHaveURL(/\/landlord$/);

    const name = `E2E Listing ${stamp}`;
    await page.goto('/landlord/properties/create');
    await page.waitForLoadState('networkidle');
    await page.getByLabel('Property name', { exact: true }).fill(name);
    await page.getByLabel('Barangay', { exact: true }).fill('Loyola Heights');
    await page.getByLabel('City', { exact: true }).selectOption('quezon-city');
    await page.getByLabel('How to pay the reservation fee').fill('GCash 0917 123 4567 (E. Test)');
    await page.getByRole('button', { name: 'Save as a draft' }).click();
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
    await page.waitForLoadState('networkidle');

    // The checklist: the account is verified and the payment details are there; a room and a photo are missing.
    const checklist = page.getByRole('list', { name: 'What a listing needs' });
    await expect(page.getByRole('heading', { level: 2, name: 'Before you publish' })).toBeVisible();
    await expect(checklist.getByRole('listitem')).toHaveCount(4);
    await expect(checklist.getByText('Still needed: A room with at least one bed or space to rent')).toBeVisible();
    await expect(checklist.getByText('Still needed: At least one photo')).toBeVisible();
    await expect(checklist.getByText('Done: We have checked your account')).toBeVisible();
    await expect(checklist.getByText('Done: How renters pay the reservation fee')).toBeVisible();
    await expect(page.getByText('2 things are still needed.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Publish', exact: true })).toBeDisabled();
    await shot('publish-checklist-incomplete');
    await clean();

    // A shared room, through the checklist's own link.
    await checklist.getByRole('link', { name: 'Add a room' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Add a room' })).toBeVisible();
    await page.waitForLoadState('networkidle');
    await page.getByLabel('Room name', { exact: true }).fill('Room 1');
    await page.getByLabel('Room type', { exact: true }).selectOption('shared');
    await page.getByLabel('Number of beds').fill('2');
    await page.getByLabel('Monthly rate (₱)').fill('3,000');
    await page.getByLabel('Reservation fee (₱)').fill('500');
    await page.getByRole('button', { name: 'Add room' }).click();
    await expect(page.getByRole('heading', { level: 3, name: /Room 1/ })).toBeVisible();
    await page.waitForLoadState('networkidle');
    await expect(page.getByText('One thing is still needed.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Publish', exact: true })).toBeDisabled();

    // A photo. The worker resizes it; the page polls, and the checklist ticks itself when it is ready.
    await page.getByLabel('Add photos').setInputFiles([fixture('bunks.jpg')]);
    await page.getByRole('button', { name: 'Upload photos' }).click();
    await expect(page.getByRole('img', { name: `Photo 1 of ${name}` })).toBeVisible({ timeout: 60_000 });
    await expect(page.getByRole('heading', { level: 2, name: 'Ready to publish' })).toBeVisible({ timeout: 15_000 });
    await expect(checklist.getByText('Still needed:')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Publish', exact: true })).toBeEnabled();
    await shot('publish-checklist-complete');
    await clean();

    await page.getByRole('button', { name: 'Publish', exact: true }).click();
    await expect(page.getByText(`${name} is published. Renters can now find and reserve it.`)).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'This property is published' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Unpublish' })).toBeVisible();
    await shot('published');
    const propertyPath = new URL(page.url()).pathname;
    expect(propertyPath).toMatch(/^\/landlord\/properties\/\d+$/);
    await clean();

    // The listing is reachable: the room page of slice 2.5, for someone who isn't logged in.
    const roomId = (await page.getByRole('link', { name: 'Edit room Room 1' }).getAttribute('href'))?.match(/rooms\/(\d+)\/edit/)?.[1];
    expect(roomId).toBeTruthy();
    const visitor = await browser.newContext(phone);
    const roomPage = await visitor.newPage();
    const roomResponse = await roomPage.goto(`/rooms/${roomId}`);
    expect(roomResponse?.status()).toBe(200);
    await expect(roomPage.getByText(name).first()).toBeVisible();
    await visitor.close();

    // ── 2. A caretaker is invited and joins through the link ─────────────────────────────────────
    const email = `e2e-caretaker-${stamp}@example.com`;
    await page.goto('/landlord/members');
    await page.waitForLoadState('networkidle');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByRole('button', { name: 'Send invitation' }).click();
    await expect(page.getByText(`We've sent an invitation to ${email}.`)).toBeVisible();
    await expect(page.locator('td[data-label="Email"]', { hasText: email })).toBeVisible();
    await expect(page.getByRole('button', { name: `Send again to ${email}` })).toBeVisible();
    await expect(page.getByRole('button', { name: `Cancel invitation for ${email}` })).toBeVisible();
    await expect(page.getByText("We don't email invitations yet")).toHaveCount(0);
    await shot('members-pending-invite');
    await clean();

    // Dev: the link from the email in Mailpit. Production: no mailbox, so the link a CI step made (another address).
    const link = isProductionRun ? (process.env.E2E_INVITATION_URL as string) : await invitationLink(request, email);
    const invitationPath = new URL(link).pathname;
    expect(invitationPath).toMatch(/^\/invitations\/[A-Za-z0-9]+$/);

    const caretakerContext = await browser.newContext(phone);
    const caretaker = await caretakerContext.newPage();
    const caretakerErrors = await collectConsoleErrors(caretaker);
    const lastName = `Caretaker${stamp}`;

    await caretaker.goto(invitationPath);
    await caretaker.waitForLoadState('networkidle');
    await expect(caretaker.getByRole('heading', { level: 1, name: /^Join / })).toBeVisible();
    await expect(caretaker.getByText(/invited you to help manage .+ as a caretaker\./)).toBeVisible();
    await expect(caretaker.getByLabel('Email', { exact: true })).toHaveAttribute('readonly', '');
    if (!isProductionRun) {
        await expect(caretaker.getByLabel('Email', { exact: true })).toHaveValue(email);
    }
    await shot('invitation-acceptance', caretaker);
    await clean(caretaker, caretakerErrors);

    await caretaker.getByLabel('First name').fill('Erin');
    await caretaker.getByLabel('Last name').fill(lastName);
    await caretaker.getByLabel('Mobile number').fill('0917 555 0142');
    await caretaker.getByLabel('Password', { exact: true }).fill('a long e2e password');
    await caretaker.getByLabel('Type the password again').fill('a long e2e password');
    await caretaker.getByRole('button', { name: 'Create my account and join' }).click();

    // On /landlord at once: no "confirm your email" step, the link proved the inbox.
    await expect(caretaker).toHaveURL(/\/landlord$/);
    await expect(caretaker.getByText(/You've joined .+ as a caretaker\./)).toBeVisible();
    await caretaker.waitForLoadState('networkidle');
    await clean(caretaker, caretakerErrors);

    // Read-only: no Members link, no "Add a property", and the new property without a single button.
    await expect(caretaker.getByRole('link', { name: 'Members' })).toHaveCount(0);
    await caretaker.goto('/landlord/properties');
    await caretaker.waitForLoadState('networkidle');
    await expect(caretaker.getByRole('heading', { level: 1, name: 'Properties' })).toBeVisible();
    await expect(caretaker.getByRole('link', { name: 'Add a property' })).toHaveCount(0);
    await caretaker.goto(propertyPath);
    await caretaker.waitForLoadState('networkidle');
    await expect(caretaker.getByRole('heading', { level: 1, name })).toBeVisible();
    await expect(caretaker.getByRole('button', { name: 'Unpublish' })).toHaveCount(0);
    await expect(caretaker.getByRole('link', { name: 'Edit property' })).toHaveCount(0);
    await expect(caretaker.getByRole('button', { name: /Archive|Rename|Delete|Add a bed/ })).toHaveCount(0);
    await clean(caretaker, caretakerErrors);
    expect((await caretaker.goto('/landlord/members'))?.status()).toBe(403);
    caretakerErrors.length = 0; // the 403 is a console error, as it should be

    // The used link is now the neutral page.
    const again = await browser.newContext(phone);
    const usedLink = await again.newPage();
    expect((await usedLink.goto(invitationPath))?.status()).toBe(404);
    await expect(usedLink.getByText("This invitation link isn't valid any more. Ask the owner to send a new one.")).toBeVisible();
    await expectNoAxeViolations(usedLink);
    await again.close();

    // ── 3. The owner removes the caretaker; their next request is refused ────────────────────────
    await page.goto('/landlord/members');
    await page.waitForLoadState('networkidle');
    const fullName = `Erin ${lastName}`;
    await expect(page.locator('td[data-label="Name"]', { hasText: fullName })).toBeVisible();
    await page.getByRole('button', { name: `Remove ${fullName}` }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: `Remove ${fullName}?` })).toBeVisible();
    await clean();
    await dialog.getByRole('button', { name: 'Remove member' }).click();
    await expect(page.getByText(`${fullName} is removed from this account.`)).toBeVisible();
    await expect(page.locator('td[data-label="Name"]', { hasText: fullName })).toHaveCount(0);
    await clean();

    expect((await caretaker.goto('/landlord'))?.status()).toBe(403);
    expect((await caretaker.goto('/landlord/properties'))?.status()).toBe(403);

    await caretakerContext.close();
    await context.close();
});

/** The invitation link from the newest mail to `email` in Mailpit (the queue worker sends it within seconds). */
async function invitationLink(request: APIRequestContext, email: string): Promise<string> {
    let id = '';
    await expect
        .poll(
            async () => {
                const response = await request.get(`${mailpitUrl}/api/v1/search`, { params: { query: `to:"${email}"` } });
                const body = (await response.json()) as { messages: { ID: string; Subject: string }[] };
                id = body.messages[0]?.ID ?? '';

                return body.messages[0]?.Subject ?? '';
            },
            { timeout: 30_000, message: `no invitation email for ${email} in Mailpit` },
        )
        .toMatch(/ invited you to .+ on ReserveVision$/);

    const message = (await (await request.get(`${mailpitUrl}/api/v1/message/${id}`)).json()) as { Text: string };
    expect(message.Text).toContain('as a caretaker');
    expect(message.Text).toContain('The link works for 7 days');
    const link = message.Text.match(/https?:\/\/\S+\/invitations\/[A-Za-z0-9]+/)?.[0];
    expect(link, message.Text).toBeTruthy();

    return link as string;
}
