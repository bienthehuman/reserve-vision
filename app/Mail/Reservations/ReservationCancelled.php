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

/** To the landlord's members: the renter cancelled. When a proof was sent, the refund is theirs to record. */
final class ReservationCancelled extends Mailable implements NoticeMail
{
    use LoadsReservationFacts, RendersNotice;

    public function __construct(public readonly int $reservationId, public readonly bool $hadProof) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: Format::t(':renter cancelled a reservation at :property', ['renter' => $this->facts()->renterName, 'property' => $this->facts()->property]));
    }

    public function notice(): Notice
    {
        $f = $this->facts();

        return new Notice(
            heading: Format::t('A reservation was cancelled'),
            paragraphs: array_values(array_filter([
                Format::t(':renter cancelled their reservation at :property. The place is free again.', ['renter' => $f->renterName, 'property' => $f->property]),
                $this->hadProof ? Format::t('They had sent a proof of payment. Record whether you returned the fee on the reservation page.') : null,
            ])),
            facts: [Format::t('Renter') => $f->renterName, ...$f->rows()],
            buttonLabel: Format::t('See the reservation'),
            buttonUrl: route('landlord.reservations.show', $f->reservationId),
        );
    }
}
