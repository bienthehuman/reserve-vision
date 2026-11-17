<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;

/** The one Markdown view every status mail shares: `mail.notice`, filled from the mailable's `notice()`. */
trait RendersNotice
{
    use Queueable;

    public function content(): Content
    {
        return new Content(markdown: 'mail.notice', text: 'mail.notice-text', with: ['notice' => $this->notice()]);
    }
}
