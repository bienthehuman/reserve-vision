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

/** To the landlord's members: a proof has waited 24 or 48 hours (RESERVATIONS.md "Unanswered proofs"). */
final class ProofReminder extends Mailable implements NoticeMail
{
    use LoadsReservationFacts, RendersNotice;

    public function __construct(public readonly int $reservationId, public readonly int $hours, public readonly string $sentAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: Format::t('Reminder: :renter has waited :hours hours for an answer', ['renter' => $this->facts()->renterName, 'hours' => $this->hours]));
    }

    public function notice(): Notice
    {
        $f = $this->facts();

        return new Notice(
            heading: Format::t('A renter is still waiting'),
            paragraphs: [Format::t(':renter paid the reservation fee and sent the proof :sent. It has waited over :hours hours. The space stays held for them until you answer.', ['renter' => $f->renterName, 'sent' => $this->sentAt, 'hours' => $this->hours])],
            facts: [Format::t('Renter') => $f->renterName, ...$f->rows()],
            buttonLabel: Format::t('Review the proof'),
            buttonUrl: route('landlord.reservations.show', $f->reservationId),
        );
    }
}
