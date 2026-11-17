{{--
    The shell of every framework error page (401, 402, 403, 404, 419, 429, 500, 503): each of those views extends
    `errors::minimal`, and this file replaces Laravel's. Laravel's has an inline <style> without a nonce, which our
    CSP blocks (the page came out unstyled). No scripts, no database, no Vite: it must work when everything else doesn't.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>@yield('title') · {{ config('app.name') }}</title>
        <style nonce="{{ Vite::cspNonce() }}">
            body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; background: #f6f4fa; color: #1f1a2e; font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.5; }
            main { width: 100%; max-width: 32rem; padding: 2rem 1.5rem; border-radius: 0.75rem; background: #fff; box-shadow: 0 1px 3px rgba(31, 26, 46, 0.12); }
            .code { margin: 0 0 0.25rem; color: #47307a; font-size: 0.875rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; }
            h1 { margin: 0 0 1rem; font-size: 1.5rem; line-height: 1.25; }
            a { color: #47307a; font-weight: 600; text-underline-offset: 0.2em; }
            a:focus-visible { outline: 3px solid #5b3e96; outline-offset: 2px; }
        </style>
    </head>
    <body>
        <main id="main">
            <p class="code">Error @yield('code')</p>
            <h1>@yield('message')</h1>
            <p><a href="{{ url('/') }}">Back to {{ config('app.name') }}</a></p>
        </main>
    </body>
</html>
