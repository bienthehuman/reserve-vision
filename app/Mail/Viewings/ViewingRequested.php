<?php

declare(strict_types=1);

namespace App\Mail\Viewings;

use App\Data\Mail\Notice;
use App\Mail\Concerns\LoadsViewingFacts;
use App\Mail\Concerns\RendersNotice;
use App\Mail\NoticeMail;
use App\Support\Mail\Format;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;

/** To the landlord's members: a renter asked to see a property. */
final class ViewingRequested extends Mailable implements NoticeMail
{
    use LoadsViewingFacts, RendersNotice;

    public function __construct(public readonly int $viewingId) {}

    public function envelope(): Envelope
    {
        $f = $this->facts();

        return new Envelope(subject: Format::t(':renter would like to see :property', ['renter' => $f->renterName, 'property' => $f->property]));
    }

    public function notice(): Notice
    {
        $f = $this->facts();

        return new Notice(
            heading: Format::t('Someone would like to see your place'),
            paragraphs: [Format::t(':renter asked to see :property. You can accept or decline it from the request.', ['renter' => $f->renterName, 'property' => $f->property])],
            facts: [Format::t('Renter') => $f->renterName, Format::t('Property') => $f->property, Format::t('Preferred time') => $f->when],
            buttonLabel: Format::t('Answer the request'),
            buttonUrl: route('landlord.viewings.show', $f->viewingId),
            quoteLabel: $f->message === null ? null : Format::t('Their message'),
            quote: $f->message,
        );
    }
}
