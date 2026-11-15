<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\Domain\CannotEditSpace;
use App\Models\Room;
use App\Models\Space;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Renames a space (owner only: SpacePolicy::update, checked by the request). Labels are
 * unique within a room. Locks the room row first, then the space; renaming never
 * changes a reservation, which points at the space's id. One audit row, `space.renamed`;
 * none if the label is the same.
 */
final class RenameSpace
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotEditSpace */
    public function handle(Space $space, string $label): Space
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));

        return DB::transaction(function () use ($space, $label): Space {
            Room::query()->whereKey($space->room_id)->lockForUpdate()->firstOrFail();
            $space = Space::query()->whereKey($space->getKey())->lockForUpdate()->firstOrFail();

            $taken = Space::query()->where('room_id', $space->room_id)->whereKeyNot($space->id)->where('label', $label)->sharedLock()->count();

            if ($taken > 0) {
                throw CannotEditSpace::labelTaken($label);
            }

            $before = ['label' => $space->label];
            $space->label = $label;
            $space->save();

            $this->audit->record('space.renamed', $space, $before, ['label' => $space->label]);

            return $space;
        });
    }
}
