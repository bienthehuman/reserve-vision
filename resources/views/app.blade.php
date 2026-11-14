<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#5B3E96">
        {{-- Read by Inertia (progress bar styles) and the Vite client (injected styles). --}}
        <meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">

        @unless (Vite::isRunningHot())
            <link rel="preload" as="font" type="font/woff2" crossorigin
                  href="{{ Vite::asset('node_modules/@fontsource-variable/plus-jakarta-sans/files/plus-jakarta-sans-latin-wght-normal.woff2') }}">
        @endunless

        @viteReactRefresh
        @vite(['resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
