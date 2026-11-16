<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PhotoStatus;
use App\Models\LandlordAccount;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\Room;
use App\Models\Space;

/**
 * What a property needs before it can be published (ACCESS.md, flow A), as the list the property
 * page shows and PublishProperty checks again: the account is verified, the property isn't
 * archived, a room that isn't archived has a space that isn't either, one photo is ready, and
 * the payment instructions are written, and the address is complete: its city and barangay are
 * chosen from the places list (slice 4.4; the form requires them, but a property from before that
 * may have only the old text).
 *
 * `of($property)` reads plainly, for the page. `of($property, locking: true)` is for
 * PublishProperty, which has already locked the property's rooms and the property row: every
 * read is then a locking read, so it sees committed rows and not the transaction's snapshot
 * (the pitfall in ADR 0001), and no subquery is used (a lock clause doesn't reach into one).
 */
final readonly class PublishChecklist
{
    /**
     * @param  list<array{key: string, label: string, done: bool, hint: string|null, fix: array{href: string, label: string}|null}>  $items
     */
    private function __construct(public array $items) {}

    public static function of(Property $property, bool $locking = false): self
    {
        $id = $property->id;

        $verified = LandlordAccount::query()->whereKey($property->landlord_account_id)
            ->when($locking, fn ($query) => $query->sharedLock())->first()?->is_verified === true;

        $roomIds = Room::query()->where('property_id', $id)->whereNull('archived_at')
            ->when($locking, fn ($query) => $query->sharedLock())->pluck('id')->all();

        $spaces = $roomIds === [] ? 0 : Space::query()->whereIn('room_id', $roomIds)->whereNull('archived_at')
            ->when($locking, fn ($query) => $query->sharedLock())->count();

        $readyPhotos = PropertyPhoto::query()->where('property_id', $id)->where('status', PhotoStatus::Ready)
            ->when($locking, fn ($query) => $query->sharedLock())->count();

        $items = [
            [
                'key' => 'verified',
                'label' => self::text('We have checked your account'),
                'done' => $verified,
                'hint' => $verified ? null : self::text('We\'re still checking your account. You can publish as soon as that\'s done.'),
                'fix' => null,
            ],
            [
                'key' => 'room',
                'label' => self::text('A room with at least one bed or space to rent'),
                'done' => $spaces > 0,
                'hint' => null,
                'fix' => ['href' => route('landlord.properties.rooms.create', $id, absolute: false), 'label' => self::text('Add a room')],
            ],
            [
                'key' => 'photo',
                'label' => self::text('At least one photo'),
                'done' => $readyPhotos > 0,
                'hint' => null,
                'fix' => ['href' => '#photos', 'label' => self::text('Add photos')],
            ],
            [
                'key' => 'payment',
                'label' => self::text('How renters pay the reservation fee'),
                'done' => trim((string) $property->payment_instructions) !== '',
                'hint' => null,
                'fix' => ['href' => route('landlord.properties.edit', $id, absolute: false), 'label' => self::text('Add how to pay')],
            ],
            [
                'key' => 'address',
                'label' => self::text('A complete address'),
                'done' => $property->hasCompleteAddress(),
                'hint' => $property->hasCompleteAddress() ? null : self::text('Choose the barangay and city from the lists.'),
                'fix' => ['href' => route('landlord.properties.edit', $id, absolute: false), 'label' => self::text('Choose from the lists')],
            ],
        ];

        if ($property->archived_at !== null) {
            $items[] = [
                'key' => 'archived',
                'label' => self::text('The property is not archived'),
                'done' => false,
                'hint' => self::text('Restore the property first.'),
                'fix' => null,
            ];
        }

        return new self($items);
    }

    private static function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }

    public function isComplete(): bool
    {
        return $this->missing() === [];
    }

    /** @return list<string> the labels of what is still needed */
    public function missing(): array
    {
        return array_values(array_map(
            fn (array $item): string => $item['label'],
            array_filter($this->items, fn (array $item): bool => ! $item['done']),
        ));
    }
}
