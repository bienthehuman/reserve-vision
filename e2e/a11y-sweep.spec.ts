/**
 * Accessibility sweep (slice 7.2): axe on every page, for every actor that can open it, in the pages' important
 * states, at both viewports (the two Playwright projects). It is built from the app's route list so a new page
 * can't skip it:
 *
 *   e2e/fixtures/page-routes.json   every GET route (`php artisan app:page-routes --write`; a Pest test fails
 *                                   when it is stale). This file maps each key to "not a page" (with the reason)
 *                                   or to the actors that open it. An unmapped route FAILS the first test, with
 *                                   the instruction to map it (the same idea as T6's unmapped parameters).
 *   PAGES / NOT_PAGES               the map. `actors` is checked against what each session really visited, so a
 *                                   mapped page that nobody visits fails too.
 *
 * Each actor is one session (guest, renter, unverified renter, owner, caretaker) that visits its pages in turn:
 * the base page, then its states (a live and an expired hold, every status's decision panel, empty lists,
 * validation errors, every dialog). Besides axe, each page must have exactly one h1, and no two routes may share a
 * document title. Violations are collected over the whole run and reported together.
 *
 * Records come from the demo data: the owner's reservations list gives one reservation per status and their
 * renters' emails (all demo accounts use the password `password`), so no state depends on a fixed id.
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { collectConsoleErrors, expectNoAxeViolationsWithoutScripts, isProductionRun, logIn } from './support';

type Actor = 'guest' | 'renter' | 'unverified' | 'owner' | 'caretaker';

interface FixtureRoute {
    key: string;
    uri: string;
    access: 'public' | 'guest' | 'auth' | 'renter' | 'landlord' | 'signed';
    parameters: string[];
}

const routes: FixtureRoute[] = JSON.parse(readFileSync(new URL('./fixtures/page-routes.json', import.meta.url), 'utf8'));

/** GET routes that are not pages: the reason is the documentation. */
const NOT_PAGES: Record<string, string> = {
    '/up': 'health check, plain text',
    'listings.count': 'JSON: the filter button\'s count',
    'places.cities': 'JSON: a province\'s cities',
    'places.barangays': 'JSON: a city\'s barangays',
    'proofs.show': 'a redirect to the signed file URL',
    'proofs.file': 'the proof file itself (image or PDF)',
    'media.photos.show': 'a photo file (the local media disk only)',
    'storage.local': 'Laravel\'s own file route',
    robots: 'robots.txt, plain text',
    sitemap: 'sitemap.xml',
    'rooms.show': 'a 301 redirect to the listing page',
    'verification.verify': 'the link in the email: verifies and redirects',
    'dev.mail.show': 'one email\'s HTML, not a page of the app (dev.mail.index lists them)',
};

/** GET routes that are pages, and who opens them. `landlord` pages: owner AND caretaker (a caretaker may get the 403 page). */
const PAGES: Record<string, { actors: Actor[]; dev?: true }> = {
    home: { actors: ['guest', 'renter', 'owner'] },
    'listings.index': { actors: ['guest', 'renter', 'owner'] },
    'listings.show': { actors: ['guest', 'renter', 'owner'] },
    login: { actors: ['guest'] },
    register: { actors: ['guest'] },
    'password.request': { actors: ['guest'] },
    'password.reset': { actors: ['guest'] },
    'invitations.show': { actors: ['guest'] },
    'verification.notice': { actors: ['unverified'] },
    'reservations.index': { actors: ['renter'] },
    'reservations.show': { actors: ['renter'] },
    'viewings.index': { actors: ['renter'] },
    'landlord.dashboard': { actors: ['owner', 'caretaker'] },
    'landlord.reservations.index': { actors: ['owner', 'caretaker'] },
    'landlord.reservations.show': { actors: ['owner', 'caretaker'] },
    'landlord.properties.index': { actors: ['owner', 'caretaker'] },
    'landlord.properties.show': { actors: ['owner', 'caretaker'] },
    'landlord.properties.create': { actors: ['owner', 'caretaker'] },
    'landlord.properties.edit': { actors: ['owner', 'caretaker'] },
    'landlord.properties.rooms.create': { actors: ['owner', 'caretaker'] },
    'landlord.rooms.edit': { actors: ['owner', 'caretaker'] },
    'landlord.members.index': { actors: ['owner', 'caretaker'] },
    'landlord.viewings.index': { actors: ['owner', 'caretaker'] },
    'landlord.viewings.show': { actors: ['owner', 'caretaker'] },
    styleguide: { actors: ['guest'], dev: true },
    'dev.mail.index': { actors: ['guest'], dev: true },
};

/** Routes a caretaker gets the 403 page for (owner-only forms). */
const OWNER_ONLY = new Set(['landlord.properties.create', 'landlord.properties.edit', 'landlord.properties.rooms.create', 'landlord.rooms.edit']);

/** What an actor's session must have visited, from the map. */
function declaredFor(actor: Actor): string[] {
    return Object.entries(PAGES)
        .filter(([, page]) => page.actors.includes(actor) && !(page.dev && isProductionRun))
        .map(([key]) => key)
        .sort();
}

interface Visit {
    /** The route key this visit counts for. */
    route: string;
    label: string;
    path: string;
    /** Expected HTTP status (default 200). */
    status?: number;
    /** A selector the page must show (the state this visit is meant to cover, e.g. an empty state). */
    expect?: string;
    /** More states on the same page (an error after submitting empty, ...). */
    states?: (page: Page) => Promise<void>;
}

// Shared by the five tests of a project, which run one after another in one worker (describe.serial below).
const titles = new Map<string, string>();
const failures: string[] = [];
const tally = { pages: 0, dialogs: 0, states: 0 };
const covered: Record<string, Set<string>> = {};

async function settle(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle');
}

async function axeViolations(page: Page, where: string): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice']).analyze();
    for (const violation of results.violations) {
        failures.push(`axe ${violation.id} (${violation.impact}) on ${where}: ${violation.nodes.map((node) => node.target.join(' ')).slice(0, 3).join(' | ')}`);
    }
}

/** Opens every button that opens a dialog (`aria-haspopup="dialog"`), runs axe with it open, and closes it again. */
async function sweepDialogs(page: Page, where: string): Promise<void> {
    const triggers = page.locator('main button[aria-haspopup="dialog"]:visible:not([disabled])');
    const count = await triggers.count();

    for (let index = 0; index < count; index++) {
        const trigger = triggers.nth(index);
        const name = (await trigger.innerText()).trim() || `dialog ${index + 1}`;
        await trigger.click();
        const dialog = page.getByRole('dialog');
        await dialog.waitFor({ state: 'visible' });
        await page.waitForTimeout(150);
        await axeViolations(page, `${where}, dialog "${name}"`);
        tally.dialogs++;

        // A dialog with a required reason: confirm it empty (blocked in the browser, no request) for its error state.
        if ((await dialog.locator('textarea').count()) > 0) {
            await dialog.locator('.modal-footer button').last().click();
            await dialog.locator('.invalid-feedback, [role="alert"]').first().waitFor({ state: 'visible', timeout: 2_000 }).catch(() => undefined);
            await axeViolations(page, `${where}, dialog "${name}" with its error`);
            tally.states++;
        }

        await page.keyboard.press('Escape');
        await dialog.waitFor({ state: 'hidden' });
    }
}

/** Submits the first form of the main content empty and waits for its error summary: the validation-error state. */
async function submitEmpty(page: Page, formName?: string): Promise<void> {
    const form = formName ? page.getByRole('form', { name: formName }) : page.locator('main form').first();
    await form.locator('button[type="submit"]').first().click();
    await page.locator('.alert-danger, .invalid-feedback, [role="alert"]').first().waitFor({ state: 'visible', timeout: 10_000 });
    await page.waitForTimeout(150);
}

/** One visit: status, one h1, a title, no console or CSP errors, axe, then the page's dialogs and states. */
async function visitPage(page: Page, actor: Actor, errors: string[], visit: Visit): Promise<void> {
    const where = `${actor} ${visit.route}${visit.label ? ` (${visit.label})` : ''} ${visit.path}`;
    errors.length = 0;
    const response = await page.goto(visit.path);
    const status = visit.status ?? 200;
    if (response?.status() !== status) {
        failures.push(`${where}: HTTP ${response?.status()}, expected ${status}`);

        return;
    }
    await settle(page);

    if (visit.expect) {
        const found = await page.locator(visit.expect).first().isVisible();
        if (!found) {
            failures.push(`${where}: the state this visit is for is not there (${visit.expect})`);
        }
    }

    const h1 = await page.locator('h1').count();
    if (h1 !== 1) {
        failures.push(`${where}: ${h1} h1 elements, expected exactly 1`);
    }

    const title = await page.title();
    if (title.trim() === '') {
        failures.push(`${where}: empty document title`);
    } else if (status === 200 && !visit.label.startsWith('state:')) {
        const previous = [...titles].find(([route, known]) => known === title && route !== visit.route);
        if (previous) {
            failures.push(`${where}: document title "${title}" is also the title of ${previous[0]}`);
        }
        titles.set(visit.route, titles.get(visit.route) ?? title);
    }

    // Before axe: it injects a <style> the CSP blocks. A 403/404 page logs "Failed to load resource" itself.
    const realErrors = errors.filter((text) => !(status !== 200 && text.includes('Failed to load resource')));
    if (realErrors.length > 0) {
        failures.push(`${where}: console errors: ${realErrors.join(' / ')}`);
    }

    await axeViolations(page, where);
    tally.pages++;
    (covered[actor] ??= new Set()).add(visit.route);

    await sweepDialogs(page, where);
    if (visit.states && status === 200) {
        await visit.states(page);
        await axeViolations(page, `${where}, after its states`);
        tally.states++;
    }
}

async function newSession(browser: Browser, as?: { email: string }): Promise<{ context: BrowserContext; page: Page; errors: string[] }> {
    const project = test.info().project.use;
    const context = await browser.newContext({ baseURL: test.info().project.use.baseURL, viewport: project.viewport ?? undefined, isMobile: project.isMobile, hasTouch: project.hasTouch });
    const page = await context.newPage();
    const errors = await collectConsoleErrors(page);
    if (as) {
        await page.goto('/login');
        await settle(page);
        await logIn(page, as.email, 'password');
        await page.waitForURL((url) => !url.pathname.startsWith('/login'));
        await settle(page);
    }

    return { context, page, errors };
}

async function runSession(page: Page, actor: Actor, errors: string[], visits: Visit[]): Promise<void> {
    for (const visit of visits) {
        try {
            await visitPage(page, actor, errors, visit);
        } catch (error) {
            failures.push(`${actor} ${visit.route} (${visit.label}) ${visit.path}: ${error instanceof Error ? error.message.split('\n')[0] : String(error)}`);
        }
    }
}

/** What each actor's session visited must be exactly what the map says it visits. */
function expectCoverageMatchesMap(actor: Actor): void {
    const visited = [...(covered[actor] ?? [])].sort();
    const declared = declaredFor(actor);
    const missing = declared.filter((key) => !visited.includes(key));
    const extra = visited.filter((key) => !declared.includes(key));
    if (missing.length > 0 || extra.length > 0) {
        failures.push(`${actor}: PAGES and the visits differ (declared but not visited: ${missing.join(', ') || 'none'}; visited but not declared: ${extra.join(', ') || 'none'})`);
    }
}

function reportAndAssert(actor: Actor, started: number): void {
    const routesVisited = covered[actor]?.size ?? 0;
    console.log(`a11y sweep [${test.info().project.name}] ${actor}: ${routesVisited} routes, ${tally.pages} page visits, ${tally.dialogs} dialogs, ${tally.states} extra states so far (${Math.round((Date.now() - started) / 1000)} s), ${failures.length} problems so far`);
}

async function links(page: Page, pattern: RegExp): Promise<string[]> {
    const hrefs = await page.locator('main a[href]').evaluateAll((anchors) => anchors.map((a) => a.getAttribute('href') ?? ''));

    return [...new Set(hrefs.filter((href) => pattern.test(href)))];
}

interface Discovered {
    /** One reservation per status: its landlord path, its renter's email. */
    reservations: { status: string; id: string; renter: string | null }[];
    viewings: { status: string; path: string }[];
    properties: string[];
    room: string | null;
}

const RESERVATION_STATUSES = ['proof_uploaded', 'held', 'confirmed', 'active', 'ended', 'declined', 'cancelled', 'expired'];
const VIEWING_STATUSES = ['requested', 'accepted', 'declined', 'done', 'cancelled'];

/** The owner reads one reservation per status, one viewing per status, the properties and a room, from the demo data. */
async function discover(page: Page): Promise<Discovered> {
    const found: Discovered = { reservations: [], viewings: [], properties: [], room: null };

    for (const status of RESERVATION_STATUSES) {
        await page.goto(`/landlord/reservations?status=${status}`);
        const [first] = await links(page, /^\/landlord\/reservations\/\d+$/);
        if (!first) {
            continue;
        }
        await page.goto(first);
        const mailto = await page.locator('main a[href^="mailto:"]').first().getAttribute('href');
        found.reservations.push({ status, id: first.split('/').pop() ?? '', renter: mailto ? mailto.replace('mailto:', '') : null });
    }

    for (const status of VIEWING_STATUSES) {
        await page.goto(`/landlord/viewings?status=${status}`);
        const [first] = await links(page, /^\/landlord\/viewings\/\d+$/);
        if (first) {
            found.viewings.push({ status, path: first });
        }
    }

    await page.goto('/landlord/properties');
    found.properties = await links(page, /^\/landlord\/properties\/\d+$/);
    // The first property with a room (a draft or an archived one may have none).
    for (const property of found.properties) {
        await page.goto(property);
        found.room = (await links(page, /^\/landlord\/rooms\/\d+\/edit$/))[0] ?? null;
        if (found.room) {
            break;
        }
    }

    return found;
}

const LAST_BED = '/listings/manila/santos-bedspace-house';

test.describe('the route list is fully mapped', () => {
    test('every GET route is a page in PAGES or a non-page in NOT_PAGES (with the reason)', () => {
        const keys = routes.map((route) => route.key);
        const unmapped = keys.filter((key) => !(key in PAGES) && !(key in NOT_PAGES));
        const stale = [...Object.keys(PAGES), ...Object.keys(NOT_PAGES)].filter((key) => !keys.includes(key));
        const both = Object.keys(PAGES).filter((key) => key in NOT_PAGES);

        expect(
            unmapped,
            `GET route(s) with no entry in e2e/a11y-sweep.spec.ts: ${unmapped.join(', ')}.\nIf it renders a page, add it to PAGES (who opens it) and give every actor that can a visit in the session below; if it is a file, a redirect or JSON, add it to NOT_PAGES with the reason.`,
        ).toEqual([]);
        expect(stale, 'entries for routes that no longer exist (regenerate the fixture: php artisan app:page-routes --write)').toEqual([]);
        expect(both, 'a route cannot be both a page and a non-page').toEqual([]);

        // The actors have to fit the middleware: a landlord page is opened by owner and caretaker, a renter page by a renter...
        for (const route of routes.filter((candidate) => candidate.key in PAGES)) {
            const { actors } = PAGES[route.key]!;
            const need: Record<FixtureRoute['access'], Actor[]> = { landlord: ['owner', 'caretaker'], renter: ['renter'], guest: ['guest'], auth: ['unverified'], public: ['guest'], signed: [] };
            for (const actor of need[route.access]) {
                expect(actors, `${route.key} is behind "${route.access}" middleware, so ${actor} must visit it`).toContain(actor);
            }
        }
    });
});

test.describe.serial('axe on every page, for every actor', () => {
    test.beforeAll(() => {
        titles.clear();
        failures.length = 0;
        for (const key of Object.keys(covered)) {
            delete covered[key];
        }
        Object.assign(tally, { pages: 0, dialogs: 0, states: 0 });
    });

    let discovered: Discovered;

    test('guest: the public and the login pages, with their states', async ({ browser }) => {
        test.setTimeout(240_000);
        const started = Date.now();
        const { context, page, errors } = await newSession(browser);
        const visits: Visit[] = [
            { route: 'home', label: '', path: '/' },
            { route: 'listings.index', label: '', path: '/listings' },
            { route: 'listings.index', label: 'state: filtered results', path: '/listings?city=manila' },
            { route: 'listings.index', label: 'state: no matches', path: '/listings?max_price=1', expect: '.empty-state' },
            { route: 'listings.show', label: '', path: LAST_BED },
            {
                route: 'login',
                label: '',
                path: '/login',
                states: async (login) => {
                    await settle(login);
                    await submitEmpty(login, 'Log in');
                },
            },
            {
                route: 'register',
                label: '',
                path: '/register',
                states: async (register) => {
                    await settle(register);
                    await submitEmpty(register);
                },
            },
            {
                route: 'password.request',
                label: '',
                path: '/forgot-password',
                states: async (forgot) => {
                    await settle(forgot);
                    await submitEmpty(forgot);
                },
            },
            { route: 'password.reset', label: '', path: '/reset-password/a11y-sweep-token?email=someone%40example.com' },
            // One neutral 404 page for a token that can't be used (docs/ACCESS.md, "Accepting an invitation").
            { route: 'invitations.show', label: 'state: a link that cannot be used', path: '/invitations/a11ysweepnotatoken', status: 404 },
            ...(isProductionRun
                ? []
                : [
                      { route: 'styleguide', label: '', path: '/styleguide' },
                      { route: 'dev.mail.index', label: '', path: '/dev/mail' },
                  ]),
        ];
        await runSession(page, 'guest', errors, visits);
        await context.close();
        expectCoverageMatchesMap('guest');
        reportAndAssert('guest', started);
    });

    test('owner: the landlord pages, every reservation status and the discovery of records', async ({ browser }) => {
        test.setTimeout(480_000);
        const started = Date.now();
        const { context, page, errors } = await newSession(browser, { email: 'owner@example.com' });
        discovered = await discover(page);
        expect(discovered.reservations.length, 'the demo data should have reservations in every status').toBeGreaterThanOrEqual(7);

        const [property] = discovered.properties;
        const visits: Visit[] = [
            { route: 'home', label: 'as owner', path: '/' },
            { route: 'listings.index', label: 'as owner', path: '/listings?city=manila' },
            { route: 'listings.show', label: 'as owner', path: LAST_BED },
            { route: 'landlord.dashboard', label: '', path: '/landlord' },
            { route: 'landlord.reservations.index', label: '', path: '/landlord/reservations' },
            { route: 'landlord.reservations.index', label: 'state: one status', path: '/landlord/reservations?status=proof_uploaded' },
            { route: 'landlord.reservations.index', label: 'state: no rows', path: '/landlord/reservations?status=expired&show=holds_expiring', expect: '.empty-state' },
            ...discovered.reservations.map((reservation): Visit => ({ route: 'landlord.reservations.show', label: `state: ${reservation.status}`, path: `/landlord/reservations/${reservation.id}` })),
            { route: 'landlord.properties.index', label: '', path: '/landlord/properties' },
            ...discovered.properties.map((path, index): Visit => ({ route: 'landlord.properties.show', label: `property ${index + 1}`, path })),
            {
                route: 'landlord.properties.create',
                label: '',
                path: '/landlord/properties/create',
                states: async (form) => {
                    await settle(form);
                    await submitEmpty(form);
                },
            },
            ...(property ? [{ route: 'landlord.properties.edit', label: '', path: `${property}/edit` }] : []),
            ...(property
                ? [
                      {
                          route: 'landlord.properties.rooms.create',
                          label: '',
                          path: `${property}/rooms/create`,
                          states: async (form: Page) => {
                              await settle(form);
                              await submitEmpty(form);
                          },
                      },
                  ]
                : []),
            ...(discovered.room ? [{ route: 'landlord.rooms.edit', label: '', path: discovered.room }] : []),
            {
                route: 'landlord.members.index',
                label: '',
                path: '/landlord/members',
                states: async (members) => {
                    await settle(members);
                    await submitEmpty(members, 'Invite a caretaker');
                },
            },
            { route: 'landlord.viewings.index', label: '', path: '/landlord/viewings' },
            { route: 'landlord.viewings.index', label: 'state: no rows', path: '/landlord/viewings?status=done&show=waiting', expect: '.empty-state' },
            ...discovered.viewings.map((viewing): Visit => ({ route: 'landlord.viewings.show', label: `state: ${viewing.status}`, path: viewing.path })),
        ];
        await runSession(page, 'owner', errors, visits);
        await context.close();
        expectCoverageMatchesMap('owner');
        reportAndAssert('owner', started);
    });

    test('caretaker: the same pages as read-only, and the 403 page on the owner-only forms', async ({ browser }) => {
        test.setTimeout(300_000);
        test.skip(!discovered, 'needs the owner test\'s discovery');
        const started = Date.now();
        const { context, page, errors } = await newSession(browser, { email: 'caretaker@example.com' });
        const [property] = discovered.properties;
        const visits: Visit[] = [
            { route: 'landlord.dashboard', label: '', path: '/landlord' },
            { route: 'landlord.reservations.index', label: '', path: '/landlord/reservations' },
            ...discovered.reservations.filter((r) => ['proof_uploaded', 'confirmed', 'held'].includes(r.status)).map((r): Visit => ({ route: 'landlord.reservations.show', label: `state: ${r.status}`, path: `/landlord/reservations/${r.id}` })),
            { route: 'landlord.properties.index', label: '', path: '/landlord/properties' },
            ...(property ? [{ route: 'landlord.properties.show', label: '', path: property }] : []),
            { route: 'landlord.properties.create', label: 'state: 403', path: '/landlord/properties/create', status: 403 },
            ...(property ? [{ route: 'landlord.properties.edit', label: 'state: 403', path: `${property}/edit`, status: 403 }] : []),
            ...(property ? [{ route: 'landlord.properties.rooms.create', label: 'state: 403', path: `${property}/rooms/create`, status: 403 }] : []),
            ...(discovered.room ? [{ route: 'landlord.rooms.edit', label: 'state: 403', path: discovered.room, status: 403 }] : []),
            // Caretakers don't see the members page (ACCESS.md matrix): the 403 page.
            { route: 'landlord.members.index', label: 'state: 403', path: '/landlord/members', status: 403 },
            { route: 'landlord.viewings.index', label: '', path: '/landlord/viewings' },
            ...discovered.viewings.slice(0, 2).map((viewing): Visit => ({ route: 'landlord.viewings.show', label: `state: ${viewing.status}`, path: viewing.path })),
        ];
        await runSession(page, 'caretaker', errors, visits);
        await context.close();
        expectCoverageMatchesMap('caretaker');
        for (const route of OWNER_ONLY) {
            expect(PAGES[route]?.actors).toContain('caretaker');
        }
        reportAndAssert('caretaker', started);
    });

    test('renters: the hold page in every status, their lists, and the public pages when logged in', async ({ browser }) => {
        test.setTimeout(480_000);
        test.skip(!discovered, 'needs the owner test\'s discovery');
        const started = Date.now();
        // One session per renter found: each status's reservation belongs to a different demo renter.
        const byRenter = new Map<string, typeof discovered.reservations>();
        for (const reservation of discovered.reservations) {
            if (reservation.renter) {
                byRenter.set(reservation.renter, [...(byRenter.get(reservation.renter) ?? []), reservation]);
            }
        }
        expect(byRenter.size, 'renters to log in as').toBeGreaterThan(0);

        let first = true;
        for (const [email, reservations] of byRenter) {
            const { context, page, errors } = await newSession(browser, { email });
            const visits: Visit[] = [
                { route: 'reservations.index', label: `renter ${email}`, path: '/reservations' },
                { route: 'viewings.index', label: `renter ${email}`, path: '/viewings' },
                ...reservations.map(
                    (reservation): Visit => ({
                        route: 'reservations.show',
                        label: `state: ${reservation.status}`,
                        path: `/reservations/${reservation.id}`,
                        // The proof form on a live hold: its validation errors.
                        states:
                            reservation.status === 'held'
                                ? async (hold) => {
                                      if (await hold.getByRole('form', { name: 'Proof of payment' }).count()) {
                                          await settle(hold);
                                          await submitEmpty(hold, 'Proof of payment');
                                      }
                                  }
                                : undefined,
                    }),
                ),
                ...(first
                    ? [
                          { route: 'home', label: 'as renter', path: '/' },
                          { route: 'listings.index', label: 'as renter', path: '/listings?city=manila' },
                          { route: 'listings.show', label: 'as renter', path: LAST_BED },
                      ]
                    : []),
            ];
            first = false;
            await runSession(page, 'renter', errors, visits);
            await context.close();
        }
        expectCoverageMatchesMap('renter');
        reportAndAssert('renter', started);
    });

    test('unverified renter: the "confirm your email" page', async ({ browser }, testInfo) => {
        test.setTimeout(120_000);
        const started = Date.now();
        const { context, page, errors } = await newSession(browser);
        const email = `a11y-sweep-${testInfo.project.name}-${Date.now()}@example.com`;
        await page.goto('/register');
        await settle(page);
        await page.getByLabel('Rent a place').check();
        await page.getByLabel('First name').fill('Sweep');
        await page.getByLabel('Last name').fill('Tester');
        await page.getByLabel('Email', { exact: true }).fill(email);
        await page.getByLabel('Mobile number').fill('0917 555 0188');
        await page.getByLabel('Password', { exact: true }).fill('a long sweep password');
        await page.getByLabel('Type the password again').fill('a long sweep password');
        await page.getByRole('button', { name: 'Create my account' }).click();
        await page.waitForURL(/\/email\/verify$/);
        await runSession(page, 'unverified', errors, [{ route: 'verification.notice', label: '', path: '/email/verify' }]);
        await context.close();
        expectCoverageMatchesMap('unverified');
        reportAndAssert('unverified', started);
    });

    test('the whole sweep: no violations, one h1 and a unique title on every page', async ({}, testInfo) => {
        const visited = Object.values(covered).reduce((total, set) => total + set.size, 0);
        const summary = `a11y sweep [${testInfo.project.name}]: ${visited} route-actor pairs (${Object.keys(PAGES).length} page routes, ${routes.length - Object.keys(PAGES).length} not pages), ${tally.pages} page visits, ${tally.dialogs} dialogs and ${tally.states} extra states (validation errors, empty lists, 403s), 1 viewport (${testInfo.project.name}); ${failures.length} problems`;
        console.log(summary);
        testInfo.annotations.push({ type: 'a11y-coverage', description: summary });
        expect(failures, summary).toEqual([]);
    });
});

test('focus lands on the page heading after an Inertia navigation, and a reload in place keeps it', async ({ page }) => {
    await page.goto('/');
    await settle(page);
    await page.getByRole('link', { name: 'Browse all' }).click();
    await expect(page).toHaveURL(/\/listings$/);
    await expect(page.locator('h1')).toHaveCount(1);
    // The heading (or the main region) has focus: a keyboard or screen reader user starts at the top of the new page.
    await expect
        .poll(() => page.evaluate(() => document.activeElement?.tagName === 'H1' || document.activeElement?.id === 'main'))
        .toBe(true);
});

// The server's HTML, before (or without) hydration, is a state of its own: the search filters are a collapsed panel
// then, a plain form after JavaScript. The public pages are judged as the server sends them.
test.describe('the public pages as the server renders them (JavaScript off)', () => {
    test.use({ javaScriptEnabled: false });

    for (const path of ['/', '/listings?city=manila', LAST_BED]) {
        test(`${path} has no axe violations`, async ({ page }) => {
            const response = await page.goto(path);
            expect(response?.status()).toBe(200);
            await expectNoAxeViolationsWithoutScripts(page);
        });
    }
});
