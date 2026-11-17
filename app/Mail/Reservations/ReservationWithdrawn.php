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

/** To the renter: the owner can't honour a confirmed reservation, with the reason. */
final class ReservationWithdrawn extends Mailable implements NoticeMail
{
    use LoadsReservationFacts, RendersNotice;

    public function __construct(public readonly int $reservationId, public readonly string $reason) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: Format::t('Your confirmed reservation at :property was withdrawn', ['property' => $this->facts()->property]));
    }

    public function notice(): Notice
    {
        $f = $this->facts();

        return new Notice(
            heading: Format::t('Your reservation was withdrawn'),
            paragraphs: [Format::t(':landlord can no longer offer you the place at :property. We are sorry. Your reservation page shows whether your fee is returned.', ['landlord' => $f->landlordName, 'property' => $f->property])],
            facts: [...$f->rows(), ...($f->landlordPhone === null ? [] : [Format::t('Landlord phone') => $f->landlordPhone])],
            buttonLabel: Format::t('See your reservation'),
            buttonUrl: route('reservations.show', $f->reservationId),
            quoteLabel: Format::t('What the landlord wrote'),
            quote: $this->reason,
        );
    }
}
