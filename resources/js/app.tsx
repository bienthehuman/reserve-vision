import '../scss/app.scss';
import { createInertiaApp, router } from '@inertiajs/react';
import type { ComponentType } from 'react';

const appName = import.meta.env.VITE_APP_NAME || 'ReserveVision';

/** The per-request CSP nonce, read from <meta property="csp-nonce"> (set by SecurityHeaders). */
function cspNonce(): string | undefined {
    if (typeof document === 'undefined') {
        return undefined;
    }

    return document.querySelector<HTMLMetaElement>('meta[property="csp-nonce"]')?.nonce || undefined;
}

let lastPath: string | null = null;
let lastVisit: { method: string; partial: boolean } | null = null;

/**
 * Keep keyboard and screen-reader users oriented after a visit (slice 7.2). Inertia swaps the page without a load, so
 * focus would stay on whatever was clicked, which is often gone: a keyboard user would start again from the top of the
 * document and a screen reader would say nothing about the new page. So the `h1` gets focus (tabindex -1: it reads out
 * the title and puts the next Tab at the start of the content) when:
 *
 * - the path changed (a link, or a form that redirects to another page), or
 * - the visit was an action (POST, PUT, PATCH, DELETE) and the focused element is gone (the button of a dialog that
 *   just closed: "Your reservation is cancelled." appears, and focus was on `body`).
 *
 * Never for a poll or any partial reload (`only`), nor a plain GET reload, nor a form with errors (same path, and its
 * own error summary takes focus), nor when something else already took focus on purpose (FlashError, an alert).
 */
function focusHeadingAfterVisit(): void {
    const path = window.location.pathname;
    const moved = lastPath !== null && lastPath !== path;
    lastPath = path;
    const visit = lastVisit;
    lastVisit = null;

    if (visit?.partial) {
        return;
    }

    // An action's dialog closes in `onSuccess`, after this event: give it a moment to restore focus (to its button, if the button is still there) before judging whether focus was lost.
    const action = visit !== null && visit.method !== 'get';

    window.setTimeout(() => {
        const active = document.activeElement;
        const lost = active === null || active === document.body || !active.isConnected;

        if (!moved && !(action && lost)) {
            return;
        }
        if (active?.closest('[role="alert"]')) {
            return;
        }
        const heading = document.querySelector<HTMLElement>('main h1') ?? document.getElementById('main');
        if (heading) {
            heading.setAttribute('tabindex', '-1');
            heading.focus({ preventScroll: true });
        }
    }, action ? 150 : 0);
}

if (typeof window !== 'undefined') {
    lastPath = window.location.pathname;
    router.on('before', (event) => {
        const { method, only } = event.detail.visit;
        lastVisit = { method, partial: only.length > 0 };
    });
    // `navigate` covers a change of page (and back/forward); `success` also fires for a post that redirects to the same URL, which `navigate` doesn't.
    router.on('navigate', focusHeadingAfterVisit);
    router.on('success', focusHeadingAfterVisit);
}

// Also the SSR entry: @inertiajs/vite wraps this call for the server build.
void createInertiaApp({
    // A page that already ends in the name (the listing page's SEO title) is left alone.
    title: (title) => (title ? (title.endsWith(appName) ? title : `${title} · ${appName}`) : appName),
    // Not eager: each page is its own chunk, so a page loads only its own JavaScript (ADR 0002).
    resolve: async (name) => {
        const pages = import.meta.glob<{ default: ComponentType }>(['./Pages/**/*.tsx', '!./Pages/**/*.test.tsx']);
        const page = pages[`./Pages/${name}.tsx`];
        if (!page) {
            throw new Error(`Page not found: ${name}`);
        }

        return (await page()).default;
    },
    nonce: cspNonce(),
    strictMode: true,
    progress: { color: '#5B3E96' },
});
