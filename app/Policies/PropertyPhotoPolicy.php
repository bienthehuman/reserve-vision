<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Policies\Concerns\ChecksAccess;
use Illuminate\Auth\Access\Response;

/** docs/ACCESS.md "Permission matrix"; mapped row by row in tests/Feature/Docs/PermissionMatrixTest.php. */
final class PropertyPhotoPolicy
{
    use ChecksAccess;

    /** View own account's properties, rooms, spaces and photos (published photos are public through the photos disk). */
    public function view(User $user, PropertyPhoto $photo): Response
    {
        return $this->landlordOn($user, $photo->landlord_account_id, self::MEMBERS);
    }

    /** Upload, reorder or delete photos: a photo of `$property`. */
    public function upload(User $user, Property $property): Response
    {
        return $this->landlordOn($user, $property->landlord_account_id, self::OWNERS);
    }

    /** Upload, reorder or delete photos: the photos of `$property`. */
    public function reorder(User $user, Property $property): Response
    {
        return $this->landlordOn($user, $property->landlord_account_id, self::OWNERS);
    }

    /** Upload, reorder or delete photos. */
    public function delete(User $user, PropertyPhoto $photo): Response
    {
        return $this->landlordOn($user, $photo->landlord_account_id, self::OWNERS);
    }
}
