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

/** To the renter: the landlord accepted the payment, the place is theirs. */
final class ReservationConfirmed extends Mailable implements NoticeMail
{
    use LoadsReservationFacts, RendersNotice;

    public function __construct(public readonly int $reservationId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: Format::t('Confirmed: your place at :property', ['property' => $this->facts()->property]));
    }

    public function notice(): Notice
    {
        $f = $this->facts();

        return new Notice(
            heading: Format::t('Your reservation is confirmed'),
            paragraphs: [Format::t(':landlord accepted your payment. Your place at :property is yours from :date.', ['landlord' => $f->landlordName, 'property' => $f->property, 'date' => $f->moveIn])],
            facts: [...$f->rows(), ...($f->landlordPhone === null ? [] : [Format::t('Landlord phone') => $f->landlordPhone])],
            buttonLabel: Format::t('See your reservation'),
            buttonUrl: route('reservations.show', $f->reservationId),
        );
    }
}
