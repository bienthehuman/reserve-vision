<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use App\Policies\Concerns\ChecksAccess;
use Illuminate\Auth\Access\Response;

/** docs/ACCESS.md "Permission matrix"; mapped row by row in tests/Feature/Docs/PermissionMatrixTest.php. */
final class RoomPolicy
{
    use ChecksAccess;

    /** View own account's properties, rooms, spaces and photos. */
    public function view(User $user, Room $room): Response
    {
        return $this->landlordOn($user, $room->landlord_account_id, self::MEMBERS);
    }

    /** Create or edit properties, rooms, spaces, rates: a room in `$property`. */
    public function create(User $user, Property $property): Response
    {
        return $this->landlordOn($user, $property->landlord_account_id, self::OWNERS);
    }

    /** Create or edit properties, rooms, spaces, rates. */
    public function update(User $user, Room $room): Response
    {
        return $this->landlordOn($user, $room->landlord_account_id, self::OWNERS);
    }

    /** Create or edit properties, rooms, spaces, rates. */
    public function updateRate(User $user, Room $room): Response
    {
        return $this->landlordOn($user, $room->landlord_account_id, self::OWNERS);
    }

    /** Archive or unarchive a room or space. */
    public function archive(User $user, Room $room): Response
    {
        return $this->landlordOn($user, $room->landlord_account_id, self::OWNERS);
    }

    /** Archive or unarchive a room or space. */
    public function unarchive(User $user, Room $room): Response
    {
        return $this->landlordOn($user, $room->landlord_account_id, self::OWNERS);
    }
}
