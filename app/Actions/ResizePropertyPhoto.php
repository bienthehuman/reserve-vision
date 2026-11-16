<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\StoresPhotoUploads;
use App\Enums\PhotoStatus;
use App\Models\PropertyPhoto;
use App\Support\Photos\PhotoProcessor;
use App\Support\Photos\UnreadablePhoto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * What the ProcessPropertyPhoto job does: reads the upload from temporary storage, writes the
 * thumbnail and the full size as WebP to the public photos disk, marks the photo `ready`, and
 * deletes the upload. The system's action, so it writes no audit row.
 *
 * Safe to run twice: the paths come from the row, so a second run writes the same two files;
 * a photo that is already ready or failed is left alone (only a leftover upload is removed).
 * If the owner deleted the photo (or uploaded it again) while this ran, the files it wrote are removed.
 */
final class ResizePropertyPhoto
{
    use StoresPhotoUploads;

    public function __construct(private readonly PhotoProcessor $processor) {}

    /** @throws UnreadablePhoto when no retry can help: the upload is gone or doesn't decode */
    public function handle(PropertyPhoto $photo): void
    {
        if ($photo->status !== PhotoStatus::Processing) {
            $this->deleteUpload($photo);

            return;
        }

        $uploads = Storage::disk(PropertyPhoto::UPLOAD_DISK);

        if (! $uploads->exists($photo->uploadPath())) {
            throw UnreadablePhoto::uploadMissing($photo->uploadPath());
        }

        $processed = $this->processor->process((string) $uploads->get($photo->uploadPath()));

        $photos = Storage::disk(PropertyPhoto::DISK);
        $photos->put($photo->thumbPath(), $processed->thumb, PropertyPhoto::WRITE_OPTIONS);
        $photos->put($photo->fullPath(), $processed->full, PropertyPhoto::WRITE_OPTIONS);

        $kept = DB::transaction(function () use ($photo, $processed): bool {
            $current = PropertyPhoto::query()->whereKey($photo->getKey())->lockForUpdate()->first();

            if ($current === null || $current->path !== $photo->path) {
                return false;
            }

            if ($current->status === PhotoStatus::Processing) {
                $current->status = PhotoStatus::Ready;
                $current->width = $processed->width;
                $current->height = $processed->height;
                $current->save();
            }

            return $current->status === PhotoStatus::Ready;
        });

        if (! $kept) {
            $this->deleteResized($photo);
        }

        $this->deleteUpload($photo);
    }
}
