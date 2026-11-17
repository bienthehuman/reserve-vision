<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mail previews · {{ config('app.name') }}</title>
    <style nonce="{{ Vite::cspNonce() }}">
        body { font-family: system-ui, sans-serif; margin: 1.5rem; }
        th, td { padding: 0.25rem 1rem 0.25rem 0; text-align: left; }
        td a { display: inline-block; padding: 0.5rem 0; }
    </style>
</head>
<body>
<main>
<h1>Mail previews</h1>
<p>Every status email, rendered with the newest reservation and viewing request in this database. Development only.</p>
@if ($mails === [])
    <p>Nothing to render yet: seed the demo data (<code>php artisan db:seed</code>).</p>
@else
    <table>
        <thead><tr><th scope="col">Email</th><th scope="col">To</th><th scope="col">Subject</th></tr></thead>
        <tbody>
        @foreach ($mails as $key => $mail)
            <tr>
                <td><a href="{{ route('dev.mail.show', $key) }}">{{ $mail['label'] }}</a></td>
                <td>{{ $mail['to'] }}</td>
                <td>{{ $mail['subject'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
</main>
</body>
</html>
