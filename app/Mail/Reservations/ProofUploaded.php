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

/** To the landlord's members: a renter sent proof of payment and is waiting for a decision. */
final class ProofUploaded extends Mailable implements NoticeMail
{
    use LoadsReservationFacts, RendersNotice;

    public function __construct(public readonly int $reservationId, public readonly string $amount, public readonly ?string $referenceNo) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: Format::t(':renter sent proof of payment for :property', ['renter' => $this->facts()->renterName, 'property' => $this->facts()->property]));
    }

    public function notice(): Notice
    {
        $f = $this->facts();

        return new Notice(
            heading: Format::t('A proof of payment is waiting for you'),
            paragraphs: [Format::t(':renter sent proof of payment for :property. Check it against your account, then confirm the reservation or send it back.', ['renter' => $f->renterName, 'property' => $f->property])],
            facts: [
                Format::t('Renter') => $f->renterName,
                ...$f->rows(),
                Format::t('Amount sent') => $this->amount,
                ...($this->referenceNo === null ? [] : [Format::t('Reference number') => $this->referenceNo]),
            ],
            buttonLabel: Format::t('Review the proof'),
            buttonUrl: route('landlord.reservations.show', $f->reservationId),
        );
    }
}
