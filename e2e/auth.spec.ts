import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { collectConsoleErrors, expectNoAxeViolations, isProductionRun, logIn, mailpitUrl } from './support';

/** Slice 2.4: every auth page, the renter sign-up flow through Mailpit, and the demo logins. */

const authPages = [
    { path: '/login', heading: 'Log in' },
    { path: '/register', heading: 'Sign up' },
    { path: '/forgot-password', heading: 'Reset your password' },
    { path: '/reset-password/e2e-token?email=someone%40example.com', heading: 'Choose a new password' },
];

for (const { path, heading } of authPages) {
    test(`${path.split('?')[0]} renders, hydrates and has zero axe violations`, async ({ page }) => {
        const errors = await collectConsoleErrors(page);

        const response = await page.goto(path);
        expect(response?.status()).toBe(200);
        await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible();
        await page.waitForLoadState('networkidle');

        expect(errors).toEqual([]);
        await expectNoAxeViolations(page);
    });
}

test('a failed login shows the error summary, focuses it, and still has zero axe violations', async ({ page }) => {
    const errors = await collectConsoleErrors(page);
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    await page.getByRole('button', { name: 'Log in', exact: true }).click();

    const summary = page.getByRole('alert').filter({ hasText: 'Please fix' });
    await expect(summary).toBeVisible();
    await expect(summary).toBeFocused();
    // Each field error is tied to its input.
    await expect(page.getByLabel('Email', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByLabel('Email', { exact: true })).toHaveAccessibleDescription(/email field is required/i);

    expect(errors).toEqual([]);
    await expectNoAxeViolations(page);
});

test.describe('renter sign-up', () => {
    test.skip(isProductionRun, 'needs Mailpit (dev stack only)');

    test('register, read the verification email from Mailpit, verify, log out, log in', async ({ page, request }, testInfo) => {
        const email = `e2e-${testInfo.project.name}-${Date.now()}@example.com`;
        const password = 'a long e2e password';
        const errors = await collectConsoleErrors(page);

        await page.goto('/register');
        await page.waitForLoadState('networkidle');
        await page.getByLabel('Rent a place').check();
        await page.getByLabel('First name').fill('Erika');
        await page.getByLabel('Last name').fill('Dela Cruz');
        await page.getByLabel('Email', { exact: true }).fill(email);
        await page.getByLabel('Mobile number').fill('0917 555 0101');
        await page.getByLabel('Password', { exact: true }).fill(password);
        await page.getByLabel('Type the password again').fill(password);
        await page.getByRole('button', { name: 'Create my account' }).click();

        await expect(page).toHaveURL(/\/email\/verify$/);
        await expect(page.getByRole('heading', { level: 1, name: 'Confirm your email' })).toBeVisible();
        await expect(page.getByText(email)).toBeVisible();
        expect(errors).toEqual([]);
        await expectNoAxeViolations(page);

        const link = await verificationLink(request, email);
        await page.goto(link);
        // Wait for hydration: the server-rendered "Log out" button does nothing until React takes over.
        await page.waitForLoadState('networkidle');

        await expect(page).toHaveURL(/\/$/);
        await expect(page.getByRole('status')).toHaveText('Your email is verified. Welcome to ReserveVision!');
        const nav = page.getByRole('navigation', { name: 'Account' });
        await expect(nav).toContainText('Erika Dela Cruz');
        await expect(nav.getByRole('link', { name: 'Dashboard' })).toHaveCount(0);

        await nav.getByRole('button', { name: 'Log out' }).click();
        await expect(nav.getByRole('link', { name: 'Log in' })).toBeVisible();

        await nav.getByRole('link', { name: 'Log in' }).click();
        await expect(page.getByRole('heading', { level: 1, name: 'Log in' })).toBeVisible();
        await logIn(page, email, password);
        await expect(page).toHaveURL(/\/$/);
        await expect(nav).toContainText('Erika Dela Cruz');
    });
});

const demoUsers = [
    { email: 'owner@example.com', lands: '/landlord', heading: 'Santos Family Rentals' },
    { email: 'caretaker@example.com', lands: '/landlord', heading: 'Santos Family Rentals' },
    { email: 'owner2@example.com', lands: '/landlord', heading: 'Cruz Lettings' },
    { email: 'renter1@example.com', lands: '/', heading: 'ReserveVision' },
    { email: 'renter2@example.com', lands: '/', heading: 'ReserveVision' },
];

for (const { email, lands, heading } of demoUsers) {
    test(`demo user ${email} logs in and lands on ${lands}`, async ({ page }) => {
        const errors = await collectConsoleErrors(page);

        await page.goto('/login');
        await page.waitForLoadState('networkidle');
        await logIn(page, email, 'password');

        await expect(page).toHaveURL(new RegExp(`${lands.replace('/', '\\/')}$`));
        await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible();
        const dashboardLinks = page.getByRole('navigation', { name: 'Account' }).getByRole('link', { name: 'Dashboard' });
        await expect(dashboardLinks).toHaveCount(lands === '/landlord' ? 1 : 0);

        expect(errors).toEqual([]);
        await expectNoAxeViolations(page);
    });
}

test('the demo panel (APP_DEMO=true only) fills the form in one tap', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    const panel = page.getByRole('region', { name: 'Demo accounts' });
    test.skip((await panel.count()) === 0, 'APP_DEMO is off on this stack');

    await panel.getByRole('button', { name: /owner2@example\.com/ }).click();
    await expect(page.getByLabel('Email', { exact: true })).toHaveValue('owner2@example.com');
    await expect(page.getByLabel('Password', { exact: true })).toHaveValue('password');

    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/\/landlord$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Cruz Lettings' })).toBeVisible();
});

/** The verification link from the newest mail to `email` in Mailpit (the queue worker sends it within seconds). */
async function verificationLink(request: APIRequestContext, email: string): Promise<string> {
    let id = '';
    await expect
        .poll(
            async () => {
                const response = await request.get(`${mailpitUrl}/api/v1/search`, { params: { query: `to:"${email}"` } });
                const body = (await response.json()) as { messages: { ID: string; Subject: string }[] };
                id = body.messages[0]?.ID ?? '';

                return body.messages[0]?.Subject ?? '';
            },
            { timeout: 30_000, message: `no verification email for ${email} in Mailpit` },
        )
        .toBe('Confirm your email for ReserveVision');

    const message = (await (await request.get(`${mailpitUrl}/api/v1/message/${id}`)).json()) as { Text: string };
    const link = message.Text.match(/https?:\/\/\S+\/email\/verify\/\d+\/[0-9a-f]+\?[^\s)\]]+/)?.[0];
    expect(link, message.Text).toBeTruthy();

    return link as string;
}

test('the show-password button reveals and hides the password', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    const password = page.getByLabel('Password', { exact: true });
    await password.fill('secret words');
    const toggle = page.getByRole('button', { name: 'Show password' });

    await toggle.click();
    await expect(password).toHaveAttribute('type', 'text');
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');

    await toggle.click();
    await expect(password).toHaveAttribute('type', 'password');
});
