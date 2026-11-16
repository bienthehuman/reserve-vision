<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Room;
use App\Models\Space;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Brings an archived space back (owner only: SpacePolicy::unarchive, checked by the
 * controller). The room's lock comes first, like every space change. Not archived: nothing
 * happens. One audit row, `space.unarchived`.
 */
final class UnarchiveSpace
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Space $space): Space
    {
        return DB::transaction(function () use ($space): Space {
            Room::query()->whereKey($space->room_id)->lockForUpdate()->firstOrFail();
            $space = Space::query()->whereKey($space->getKey())->lockForUpdate()->firstOrFail();

            if ($space->archived_at === null) {
                return $space;
            }

            $before = ['archived_at' => $space->archived_at];
            $space->archived_at = null;
            $space->save();

            $this->audit->record('space.unarchived', $space, $before, ['archived_at' => null]);

            return $space;
        });
    }
}
