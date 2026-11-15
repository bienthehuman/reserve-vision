<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use App\Policies\Concerns\ChecksAccess;
use Illuminate\Auth\Access\Response;

/** docs/ACCESS.md "Permission matrix"; mapped row by row in tests/Feature/Docs/PermissionMatrixTest.php. */
final class AuditLogPolicy
{
    use ChecksAccess;

    /** View audit log: the account's list. */
    public function viewAny(User $user): Response
    {
        return $this->landlordRole($user, self::OWNERS);
    }

    /** View audit log: one entry. */
    public function view(User $user, AuditLog $entry): Response
    {
        return $this->landlordOn($user, $entry->landlord_account_id, self::OWNERS);
    }
}
