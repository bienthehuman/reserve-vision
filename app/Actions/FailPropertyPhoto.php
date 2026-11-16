<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\StoresPhotoUploads;
use App\Enums\PhotoStatus;
use App\Models\PropertyPhoto;
use Illuminate\Support\Facades\DB;

/**
 * What the ProcessPropertyPhoto job does when it has given up: marks the photo `failed`, and
 * removes the upload and anything half-written. The row stays, so the owner sees what happened
 * and can upload the photo again or delete it. The system's action, so it writes no audit row.
 */
final class FailPropertyPhoto
{
    use StoresPhotoUploads;

    public function handle(PropertyPhoto $photo): void
    {
        $failed = DB::transaction(function () use ($photo): bool {
            $current = PropertyPhoto::query()->whereKey($photo->getKey())->lockForUpdate()->first();

            if ($current === null || $current->path !== $photo->path || $current->status !== PhotoStatus::Processing) {
                return false;
            }

            $current->status = PhotoStatus::Failed;
            $current->save();

            return true;
        });

        if ($failed) {
            $this->deleteResized($photo);
        }

        $this->deleteUpload($photo);
    }
}
