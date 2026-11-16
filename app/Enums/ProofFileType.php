<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a payment proof file is, detected from its content (App\Support\Proofs\ProofFileInspector).
 * The value is the MIME type the file is served with; it mirrors the `payment_proofs.mime_type` CHECK.
 */
enum ProofFileType: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';
    case Pdf = 'application/pdf';

    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
            self::Pdf => 'pdf',
        };
    }

    public function isImage(): bool
    {
        return $this !== self::Pdf;
    }
}
