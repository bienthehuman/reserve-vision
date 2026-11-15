<?php

declare(strict_types=1);

namespace App\Mail\Auth;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class VerifyEmailMail extends Mailable
{
    public function __construct(
        public readonly User $user,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Confirm your email for :app', ['app' => config()->string('app.name')]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.auth.verify-email', with: [
            'firstName' => $this->user->first_name,
            'isLandlord' => $this->user->isLandlord(),
        ]);
    }
}
