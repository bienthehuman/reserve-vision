<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\StoresPhotoUploads;
use App\Enums\PhotoStatus;
use App\Exceptions\Domain\CannotEditPhoto;
use App\Jobs\ProcessPropertyPhoto;
use App\Models\PropertyPhoto;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use SplFileInfo;
use Throwable;

/**
 * Uploads a failed photo again (owner only: PropertyPhotoPolicy::retry, checked by the request).
 * The row keeps its place and description, gets a new random path, and goes back to `processing`.
 * One audit row, `photo.retried`.
 */
final class RetryPropertyPhoto
{
    use StoresPhotoUploads;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotEditPhoto */
    public function handle(PropertyPhoto $photo, SplFileInfo $file): PropertyPhoto
    {
        $photo = DB::transaction(function () use ($photo, $file): PropertyPhoto {
            $photo = PropertyPhoto::query()->whereKey($photo->getKey())->lockForUpdate()->firstOrFail();

            if ($photo->status !== PhotoStatus::Failed) {
                throw CannotEditPhoto::notFailed();
            }

            $before = ['path' => $photo->path, 'status' => $photo->status];
            $photo->path = PropertyPhoto::newPath();
            $photo->status = PhotoStatus::Processing;
            $photo->save();

            $this->audit->record('photo.retried', $photo, $before, ['path' => $photo->path, 'status' => $photo->status]);

            try {
                $this->storeUpload($photo, $file);
            } catch (Throwable $e) {
                $this->deleteUpload($photo);

                throw $e;
            }

            return $photo;
        });

        ProcessPropertyPhoto::dispatch($photo->id);

        return $photo;
    }
}
