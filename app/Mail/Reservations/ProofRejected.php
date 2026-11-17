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

/** To the renter: the proof was sent back, with the reason and a new 24-hour hold. */
final class ProofRejected extends Mailable implements NoticeMail
{
    use LoadsReservationFacts, RendersNotice;

    public function __construct(public readonly int $reservationId, public readonly string $reason) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: Format::t('Please send a new proof for :property', ['property' => $this->facts()->property]));
    }

    public function notice(): Notice
    {
        $f = $this->facts();

        return new Notice(
            heading: Format::t('Please send your proof again'),
            paragraphs: array_values(array_filter([
                Format::t(':landlord could not accept your proof of payment for :property. Your place is still held.', ['landlord' => $f->landlordName, 'property' => $f->property]),
                $f->holdEnds === null ? null : Format::t('Send a new proof before :when.', ['when' => $f->holdEnds]),
            ])),
            facts: $f->rows(),
            buttonLabel: Format::t('Send a new proof'),
            buttonUrl: route('reservations.show', $f->reservationId),
            quoteLabel: Format::t('What the landlord wrote'),
            quote: $this->reason,
        );
    }
}
