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

/** To the renter: the landlord turned the reservation down, with the reason. */
final class ReservationDeclined extends Mailable implements NoticeMail
{
    use LoadsReservationFacts, RendersNotice;

    public function __construct(public readonly int $reservationId, public readonly string $reason) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: Format::t('Your reservation at :property was declined', ['property' => $this->facts()->property]));
    }

    public function notice(): Notice
    {
        $f = $this->facts();

        return new Notice(
            heading: Format::t('Your reservation was declined'),
            paragraphs: [Format::t(':landlord could not accept your reservation at :property. The place is free again. Whether your fee is returned is up to the landlord: you will see it on your reservation page.', ['landlord' => $f->landlordName, 'property' => $f->property])],
            facts: [...$f->rows(), ...($f->landlordPhone === null ? [] : [Format::t('Landlord phone') => $f->landlordPhone])],
            buttonLabel: Format::t('See your reservation'),
            buttonUrl: route('reservations.show', $f->reservationId),
            quoteLabel: Format::t('What the landlord wrote'),
            quote: $this->reason,
        );
    }
}
