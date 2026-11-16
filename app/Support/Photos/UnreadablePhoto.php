<?php

declare(strict_types=1);

namespace App\Support\Photos;

use RuntimeException;

/** The bytes aren't a JPEG, PNG or WebP that decodes, or the upload is gone. Retrying can't help. */
final class UnreadablePhoto extends RuntimeException
{
    public static function notAnImage(): self
    {
        return new self('The file is not a JPEG, PNG or WebP image that can be decoded.');
    }

    public static function uploadMissing(string $path): self
    {
        return new self("The uploaded file [{$path}] is no longer in temporary storage.");
    }
}
