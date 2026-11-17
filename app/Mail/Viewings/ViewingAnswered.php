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

/** To the renter: the landlord accepted or declined the viewing, with their note. An accepted one carries the address and phone. */
final class ViewingAnswered extends Mailable implements NoticeMail
{
    use LoadsViewingFacts, RendersNotice;

    public function __construct(public readonly int $viewingId, public readonly bool $accepted) {}

    public function envelope(): Envelope
    {
        $property = $this->facts()->property;

        return new Envelope(subject: $this->accepted
            ? Format::t('Your viewing at :property is accepted', ['property' => $property])
            : Format::t('Your viewing request at :property was declined', ['property' => $property]));
    }

    public function notice(): Notice
    {
        $f = $this->facts();

        return new Notice(
            heading: $this->accepted ? Format::t('Your viewing is accepted') : Format::t('Your viewing request was declined'),
            paragraphs: [$this->accepted
                ? Format::t(':landlord will see you at :property on :when.', ['landlord' => $f->landlordName, 'property' => $f->property, 'when' => $f->when])
                : Format::t(':landlord could not see you at :property on :when. You can ask for another time.', ['landlord' => $f->landlordName, 'property' => $f->property, 'when' => $f->when])],
            facts: [
                Format::t('Property') => $f->property,
                Format::t('When') => $f->when,
                ...($this->accepted ? [Format::t('Address') => $f->address] : []),
                ...($this->accepted && $f->landlordPhone !== null ? [Format::t('Landlord phone') => $f->landlordPhone] : []),
            ],
            buttonLabel: Format::t('See your viewing requests'),
            buttonUrl: route('viewings.index'),
            quoteLabel: $f->note === null ? null : Format::t('What the landlord wrote'),
            quote: $f->note,
        );
    }
}
