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

/** To the renter: the confirmation was taken back and the proof is under review again. */
final class ConfirmationUndone extends Mailable implements NoticeMail
{
    use LoadsReservationFacts, RendersNotice;

    public function __construct(public readonly int $reservationId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: Format::t('Your confirmation at :property is under review again', ['property' => $this->facts()->property]));
    }

    public function notice(): Notice
    {
        $f = $this->facts();

        return new Notice(
            heading: Format::t('Your reservation is under review again'),
            paragraphs: [Format::t(':landlord took back the confirmation and is looking at your proof again. Your place is still held for you. You will get another email when they decide.', ['landlord' => $f->landlordName])],
            facts: $f->rows(),
            buttonLabel: Format::t('See your reservation'),
            buttonUrl: route('reservations.show', $f->reservationId),
        );
    }
}
