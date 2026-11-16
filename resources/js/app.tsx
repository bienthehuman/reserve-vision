import '../scss/app.scss';
import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';

const appName = import.meta.env.VITE_APP_NAME || 'ReserveVision';

/** The per-request CSP nonce, read from <meta property="csp-nonce"> (set by SecurityHeaders). */
function cspNonce(): string | undefined {
    if (typeof document === 'undefined') {
        return undefined;
    }

    return document.querySelector<HTMLMetaElement>('meta[property="csp-nonce"]')?.nonce || undefined;
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
