import inertia from '@inertiajs/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'node:url';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ command, mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    // The dev server runs in the `ssr` container; the browser reaches it on the
    // same port through the compose port mapping.
    const port = Number(env.VITE_PORT ?? 5183);

    return {
        plugins: [
            laravel({
                input: ['resources/js/app.tsx'],
                refresh: true,
            }),
            // Resolves resources/js/app.tsx as the SSR entry (dev endpoint
            // /__inertia_ssr, and `vite build --ssr` for production).
            inertia(),
            react(),
        ],
        resolve: {
            alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
        },
        // Pre-bundle dependencies at startup. Without this, the first page load on a
        // cold dev server discovers them, re-optimizes, and fails with
        // "504 Outdated Optimize Dep" (flaky e2e right after `bin/setup`).
        optimizeDeps: {
            include: [
                'react',
                'react/jsx-dev-runtime',
                'react-dom/client',
                '@inertiajs/react',
                'react-bootstrap',
                'react-bootstrap-icons',
            ],
        },
        // Production SSR bundle (`vite build --ssr`) is self-contained, so the
        // production image needs only the node binary, no node_modules (slice 1.3).
        // The dev server keeps dependencies external.
        ssr: command === 'build' ? { noExternal: true } : undefined,
        css: {
            preprocessorOptions: {
                scss: {
                    // Bootstrap 5.3 still needs @import. Silence the deprecation
                    // for that, and hide warnings coming from vendor files only.
                    quietDeps: true,
                    silenceDeprecations: ['import'],
                },
            },
        },
        server: {
            host: '0.0.0.0',
            port,
            strictPort: true,
            // The app container calls the SSR endpoint as http://ssr:<port>.
            allowedHosts: ['ssr'],
            origin: `http://localhost:${port}`,
            // Setting `origin` replaces laravel-vite-plugin's CORS list, so allow the app explicitly.
            cors: { origin: env.APP_URL ?? 'http://localhost:8180' },
            hmr: { host: 'localhost', port, clientPort: port },
            warmup: {
                clientFiles: ['./resources/js/app.tsx', './resources/js/Pages/**/*.tsx'],
            },
            watch: {
                ignored: ['**/storage/framework/views/**', '**/vendor/**'],
            },
        },
    };
});
