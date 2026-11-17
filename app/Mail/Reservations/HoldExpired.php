<?php

declare(strict_types=1);

namespace App\Mail\Reservations;

use App\Data\Mail\Notice;
use App\Mail\Concerns\LoadsReservationFacts;
use App\Mail\Concerns\RendersNotice;
use App\Mail\NoticeMail;
use App\Support\Mail\Format;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;

/** To the renter: the hold ran out without a proof, and the place was released. */
final class HoldExpired extends Mailable implements NoticeMail
{
    use LoadsReservationFacts, RendersNotice;

    public function __construct(public readonly int $reservationId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: Format::t('Your hold at :property has ended', ['property' => $this->facts()->property]));
    }

    public function notice(): Notice
    {
        $f = $this->facts();

        return new Notice(
            heading: Format::t('Your hold has ended'),
            paragraphs: [Format::t('We did not get a proof of payment for :property in time, so the place was released. You have not been charged by us. If you still want it, look at the listing and reserve again: someone else may have taken it.', ['property' => $f->property])],
            facts: $f->rows(),
            buttonLabel: Format::t('See the listing'),
            buttonUrl: url($f->listingPath),
        );
    }
}
