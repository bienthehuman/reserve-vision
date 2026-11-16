<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\ProofFileType;
use App\Support\Proofs\ProofFileInspector;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * An uploaded payment proof, judged by its CONTENT (slice 5.3): a JPEG, PNG, WebP or PDF by its
 * first bytes (not its name, not the MIME type the browser sent), at most 5 MB; an image must
 * decode completely and a PDF must be readable enough to count its pages. A renamed file, a
 * truncated download and a password-protected PDF all fail here.
 */
final class PaymentProofFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(__('The file did not arrive. A proof can be up to 5 MB.'));

            return;
        }

        if ($value->getSize() > ProofFileInspector::MAX_BYTES) {
            $fail(__('This file is larger than 5 MB. Take a screenshot of the receipt instead, or choose a smaller file.'));

            return;
        }

        $inspector = app(ProofFileInspector::class);
        $type = $inspector->detect($value->getPathname());

        if ($type === null) {
            $fail(__('This file is not a JPEG, PNG, WebP or PDF. Upload a screenshot or photo of the receipt, or a PDF.'));

            return;
        }

        if ($type->isImage() && $inspector->tooManyPixels($value->getPathname())) {
            $fail(__('This image is too large to open. Take a screenshot of the receipt instead.'));

            return;
        }

        if (! $inspector->reads($value->getPathname(), $type)) {
            $fail($type === ProofFileType::Pdf
                ? __('This PDF could not be read. It may be damaged or password-protected; save it again without a password, or upload a screenshot.')
                : __('This image could not be read. The file may be damaged; take a new screenshot and try again.'));
        }
    }
}
