<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\StoresPhotoUploads;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a photo in any status (owner only: PropertyPhotoPolicy::delete): the row, then both
 * WebP files and any upload still waiting. The photos after it move up one place. The row goes
 * first, inside the transaction; the resize job checks for it before it marks a photo ready,
 * and removes what it wrote if the row is gone.
 * One audit row, `photo.deleted`.
 */
final class DeletePropertyPhoto
{
    use StoresPhotoUploads;

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PropertyPhoto $photo): void
    {
        $deleted = DB::transaction(function () use ($photo): ?PropertyPhoto {
            Property::query()->whereKey($photo->property_id)->lockForUpdate()->firstOrFail();
            $photos = PropertyPhoto::query()->where('property_id', $photo->property_id)->orderBy('sort')->orderBy('id')->lockForUpdate()->get();
            $photo = $photos->first(fn (PropertyPhoto $other): bool => $other->is($photo));

            if ($photo === null) {
                return null;
            }

            $this->audit->record('photo.deleted', $photo, ['path' => $photo->path, 'alt' => $photo->alt, 'sort' => $photo->sort, 'status' => $photo->status], []);
            $photo->delete();

            foreach ($photos->reject(fn (PropertyPhoto $other): bool => $other->is($photo))->values() as $index => $each) {
                $each->sort = $index + 1;
                $each->save();
            }

            return $photo;
        });

        if ($deleted !== null) {
            $this->deleteResized($deleted);
            $this->deleteUpload($deleted);
        }
    }
}
