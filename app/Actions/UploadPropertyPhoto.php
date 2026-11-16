<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\StoresPhotoUploads;
use App\Enums\PhotoStatus;
use App\Exceptions\Domain\CannotEditPhoto;
use App\Jobs\ProcessPropertyPhoto;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use SplFileInfo;
use Throwable;

/**
 * Adds one photo to a property (owner only: PropertyPhotoPolicy::upload, checked by the request,
 * which has also checked that the file is a JPEG, PNG or WebP that decodes).
 *
 * The file goes to temporary storage that the worker can read, a `processing` row is created
 * at the end of the order, and ProcessPropertyPhoto is queued after the commit. The property
 * row is locked first, so two uploads can't both take the twelfth place.
 * One audit row, `photo.uploaded`.
 */
final class UploadPropertyPhoto
{
    use StoresPhotoUploads;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotEditPhoto */
    public function handle(Property $property, SplFileInfo $file): PropertyPhoto
    {
        $photo = DB::transaction(function () use ($property, $file): PropertyPhoto {
            $property = Property::query()->whereKey($property->getKey())->lockForUpdate()->firstOrFail();
            $existing = PropertyPhoto::query()->where('property_id', $property->id)->lockForUpdate()->get();

            if ($existing->count() >= PropertyPhoto::LIMIT) {
                throw CannotEditPhoto::limitReached(PropertyPhoto::LIMIT);
            }

            $photo = PropertyPhoto::create([
                'property_id' => $property->id,
                'landlord_account_id' => $property->landlord_account_id,
                'path' => PropertyPhoto::newPath(),
                'sort' => $existing->count() + 1,
                'status' => PhotoStatus::Processing,
            ]);

            $this->audit->record('photo.uploaded', $photo, [], ['property_id' => $property->id, 'path' => $photo->path, 'sort' => $photo->sort]);

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
