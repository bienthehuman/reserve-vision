<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Property;
use App\Models\User;
use App\Models\ViewingRequest;
use App\Policies\Concerns\ChecksAccess;
use Illuminate\Auth\Access\Response;

/** docs/ACCESS.md "Permission matrix"; mapped row by row in tests/Feature/Docs/PermissionMatrixTest.php. */
final class ViewingRequestPolicy
{
    use ChecksAccess;

    /** Request or cancel a viewing: of `$property`. */
    public function create(User $user, Property $property): Response
    {
        return $this->renter($user);
    }

    /** Request or cancel a viewing. */
    public function cancel(User $user, ViewingRequest $viewing): Response
    {
        return $this->renterOwns($user, $viewing->renter_id);
    }

    /** Answer viewing requests: accept or decline. */
    public function answer(User $user, ViewingRequest $viewing): Response
    {
        return $this->landlordOn($user, $viewing->landlord_account_id, self::MEMBERS);
    }

    /** Answer viewing requests: mark done (flow C). */
    public function markDone(User $user, ViewingRequest $viewing): Response
    {
        return $this->landlordOn($user, $viewing->landlord_account_id, self::MEMBERS);
    }

    /** See a renter's contact details. */
    public function viewRenterContact(User $user, ViewingRequest $viewing): Response
    {
        return $this->renterContact($user, $viewing->landlord_account_id, $viewing->renter_id);
    }
}
