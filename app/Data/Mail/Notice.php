<?php

declare(strict_types=1);

namespace App\Data\Mail;

/**
 * What every status email says (slice 6.3): a heading, a few plain paragraphs, the facts of the stay as
 * label/value rows, an optional quoted message (a reason, a note) and **one** button. Rendered by
 * `resources/views/mail/notice.blade.php`, so every mail looks the same and says the same things in the same order.
 */
final readonly class Notice
{
    /**
     * @param  list<string>  $paragraphs
     * @param  array<string, string>  $facts
     */
    public function __construct(
        public string $heading,
        public array $paragraphs,
        public array $facts,
        public string $buttonLabel,
        public string $buttonUrl,
        public ?string $quoteLabel = null,
        public ?string $quote = null,
        public ?string $footnote = null,
    ) {}
}
