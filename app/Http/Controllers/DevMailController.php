<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\NoticeMail;
use App\Models\Reservation;
use App\Models\ViewingRequest;
use App\Support\Mail\MailCatalog;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

/**
 * Development only (like the styleguide, checked per request so a cached route file can't expose it): every status
 * email rendered with the newest reservation and viewing request in the database (slice 6.3). `/dev/mail` lists
 * them, `/dev/mail/{key}` shows one exactly as Mailpit would. 404 in production.
 */
final class DevMailController
{
    public function index(): Response
    {
        abort_if(app()->isProduction(), 404);

        $mails = $this->catalog();

        return response(View::make('dev.mail-index', [
            'mails' => $mails === null ? [] : array_map(fn (array $m): array => [...$m, 'subject' => (string) $m['mail']->envelope()->subject], $mails),
        ])->render());
    }

    public function show(string $key): Response
    {
        abort_if(app()->isProduction(), 404);

        $mail = ($this->catalog() ?? [])[$key]['mail'] ?? null;
        abort_if($mail === null, 404);

        // The mail's own <style> block needs `style-src 'unsafe-inline'`; SecurityHeaders leaves a CSP the response set.
        return response($mail->render(), headers: ['Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:"]);
    }

    /** @return array<string, array{label: string, to: string, mail: NoticeMail}>|null null when there is no demo data to render */
    private function catalog(): ?array
    {
        $reservation = Reservation::query()->latest('id')->first();
        $viewing = ViewingRequest::query()->latest('id')->first();

        return $reservation === null || $viewing === null ? null : MailCatalog::all($reservation->id, $viewing->id);
    }
}
