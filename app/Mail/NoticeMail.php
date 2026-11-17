<?php

declare(strict_types=1);

namespace App\Mail;

use App\Data\Mail\Notice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailables\Envelope;

/** A queued status email built from one `Notice` (slice 6.3). */
interface NoticeMail extends ShouldQueue
{
    public function notice(): Notice;

    public function envelope(): Envelope;

    /** @return string the rendered HTML */
    public function render();

    /** @return $this */
    public function afterCommit();
}
