<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Property;
use App\Models\User;
use App\Policies\Concerns\ChecksAccess;
use Illuminate\Auth\Access\Response;

/** docs/ACCESS.md "Permission matrix"; mapped row by row in tests/Feature/Docs/PermissionMatrixTest.php. */
final class PropertyPolicy
{
    use ChecksAccess;

    /** View own account's properties, rooms, spaces and photos. */
    public function view(User $user, Property $property): Response
    {
        return $this->landlordOn($user, $property->landlord_account_id, self::MEMBERS);
    }

    /** Create or edit properties, rooms, spaces, rates. */
    public function create(User $user): Response
    {
        return $this->landlordRole($user, self::OWNERS);
    }

    /** Create or edit properties, rooms, spaces, rates. */
    public function update(User $user, Property $property): Response
    {
        return $this->landlordOn($user, $property->landlord_account_id, self::OWNERS);
    }

    /** Publish or unpublish a property. */
    public function publish(User $user, Property $property): Response
    {
        return $this->landlordOn($user, $property->landlord_account_id, self::OWNERS);
    }

    /** Publish or unpublish a property. */
    public function unpublish(User $user, Property $property): Response
    {
        return $this->landlordOn($user, $property->landlord_account_id, self::OWNERS);
    }
}
