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

/** To the renter: the hold is theirs, how to pay, and until when (Reserve → `held`). */
final class HoldCreated extends Mailable implements NoticeMail
{
    use LoadsReservationFacts, RendersNotice;

    public function __construct(public readonly int $reservationId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: Format::t('Your hold at :property is ready', ['property' => $this->facts()->property]));
    }

    public function notice(): Notice
    {
        $f = $this->facts();

        return new Notice(
            heading: Format::t('Your hold is ready'),
            paragraphs: array_values(array_filter([
                Format::t('We are keeping a place for you at :property. Pay the reservation fee and send the proof before the hold ends, or it is released.', ['property' => $f->property]),
                $f->holdEnds === null ? null : Format::t('Your hold ends :when.', ['when' => $f->holdEnds]),
            ])),
            facts: [
                ...$f->rows(),
                Format::t('How to pay') => $f->paymentInstructions ?? Format::t('Ask the landlord.'),
                ...($f->landlordPhone === null ? [] : [Format::t('Landlord phone') => $f->landlordPhone]),
            ],
            buttonLabel: Format::t('Send your proof of payment'),
            buttonUrl: route('reservations.show', $f->reservationId),
        );
    }
}
