import inertia from '@inertiajs/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'node:url';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
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
            watch: {
                ignored: ['**/storage/framework/views/**', '**/vendor/**'],
            },
        },
    };
});
