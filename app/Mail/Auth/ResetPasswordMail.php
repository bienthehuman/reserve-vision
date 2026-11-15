<?php

declare(strict_types=1);

namespace App\Mail\Auth;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ResetPasswordMail extends Mailable
{
    public function __construct(
        public readonly User $user,
        public readonly string $url,
        public readonly int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Reset your :app password', ['app' => config()->string('app.name')]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.auth.reset-password', with: [
            'firstName' => $this->user->first_name,
        ]);
    }
}
