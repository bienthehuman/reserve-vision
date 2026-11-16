<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Models\PropertyPhoto;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SplFileInfo;

/** Photo files on the two media disks (slice 4.2): the upload waiting for the resize job, and the two WebP files. */
trait StoresPhotoUploads
{
    /** Copies the upload to where the worker can read it (PropertyPhoto::UPLOAD_DISK), as a stream. */
    private function storeUpload(PropertyPhoto $photo, SplFileInfo $file): void
    {
        $stream = fopen($file->getPathname(), 'rb');

        if ($stream === false) {
            throw new RuntimeException("Could not read the uploaded file [{$file->getPathname()}].");
        }

        try {
            Storage::disk(PropertyPhoto::UPLOAD_DISK)->writeStream($photo->uploadPath(), $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** Best effort: a file that is already gone is fine, and a storage hiccup must not undo the database change. */
    private function deleteUpload(PropertyPhoto $photo): void
    {
        rescue(fn () => Storage::disk(PropertyPhoto::UPLOAD_DISK)->delete($photo->uploadPath()));
    }

    private function deleteResized(PropertyPhoto $photo): void
    {
        rescue(fn () => Storage::disk(PropertyPhoto::DISK)->delete([$photo->thumbPath(), $photo->fullPath()]));
    }
}
