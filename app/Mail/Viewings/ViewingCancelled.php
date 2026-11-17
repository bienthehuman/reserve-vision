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

/** To the landlord's members: the renter cancelled their viewing request. */
final class ViewingCancelled extends Mailable implements NoticeMail
{
    use LoadsViewingFacts, RendersNotice;

    public function __construct(public readonly int $viewingId) {}

    public function envelope(): Envelope
    {
        $f = $this->facts();

        return new Envelope(subject: Format::t(':renter cancelled their viewing at :property', ['renter' => $f->renterName, 'property' => $f->property]));
    }

    public function notice(): Notice
    {
        $f = $this->facts();

        return new Notice(
            heading: Format::t('A viewing was cancelled'),
            paragraphs: [Format::t(':renter cancelled the viewing at :property planned for :when. You do not need to be there.', ['renter' => $f->renterName, 'property' => $f->property, 'when' => $f->when])],
            facts: [Format::t('Renter') => $f->renterName, Format::t('Property') => $f->property, Format::t('Was planned for') => $f->when],
            buttonLabel: Format::t('See the request'),
            buttonUrl: route('landlord.viewings.show', $f->viewingId),
        );
    }
}
