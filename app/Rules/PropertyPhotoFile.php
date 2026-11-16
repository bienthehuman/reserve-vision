<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Photos\PhotoProcessor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * An uploaded property photo, judged by its CONTENT: a JPEG, PNG or WebP by its header (not its
 * name, not the MIME type the browser sent), at most 10 MB, at least 800 px on the longer side,
 * and the whole file must decode. A renamed PDF, a truncated download and a HEIC all fail here.
 */
final class PropertyPhotoFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(__('The photo did not arrive. Photos can be up to 10 MB each.'));

            return;
        }

        if ($value->getSize() > PhotoProcessor::MAX_BYTES) {
            $fail(__('This photo is larger than 10 MB. Choose a smaller one, or export it at a lower quality.'));

            return;
        }

        $processor = app(PhotoProcessor::class);
        $image = $processor->probe($value->getPathname());

        if ($image === null) {
            $fail(__('This file is not a JPEG, PNG or WebP photo.'));

            return;
        }

        if (max($image['width'], $image['height']) < PhotoProcessor::MIN_LONG_SIDE) {
            $fail(__('This photo is too small (:width × :height). It needs at least :min pixels on its longer side.', [
                'width' => $image['width'], 'height' => $image['height'], 'min' => PhotoProcessor::MIN_LONG_SIDE,
            ]));

            return;
        }

        if ($image['width'] * $image['height'] > PhotoProcessor::MAX_PIXELS) {
            $fail(__('This photo has too many pixels (:width × :height). Resize it to 40 megapixels or fewer.', [
                'width' => $image['width'], 'height' => $image['height'],
            ]));

            return;
        }

        if (! $processor->decodes($value->getPathname())) {
            $fail(__('This photo could not be read. The file may be damaged; try exporting it again.'));
        }
    }
}
