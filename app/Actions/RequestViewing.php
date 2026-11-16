<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\UserType;
use App\Enums\ViewingRequestStatus;
use App\Exceptions\Domain\CannotRequestViewing;
use App\Exceptions\Domain\NotARenter;
use App\Models\Property;
use App\Models\User;
use App\Models\ViewingRequest;
use App\Support\Viewings\ViewingSlots;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A renter asks to see a property in person (docs/ACCESS.md flow C). The time: from today to 30 days ahead,
 * 8:00 AM to 6:00 PM Manila time in 30-minute steps, and at least ViewingSlots::LEAD_HOURS from now. Limits:
 * ViewingRequest::MAX_OPEN open requests per renter and MAX_OPEN_PER_PROPERTY per property, checked under a lock
 * on the renter row (first statement, every read a locking read: the snapshot pitfall in ReserveSpace::reserve()),
 * so one renter's parallel requests can't both pass. The new request is `requested`; no audit row (a renter action).
 */
final class RequestViewing
{
    /** Attempts in all, so up to two retries after a deadlock. */
    public const int ATTEMPTS = 3;

    /**
     * @throws NotARenter
     * @throws CannotRequestViewing
     */
    public function handle(User $renter, Property $property, CarbonImmutable $preferredAt, ?string $message): ViewingRequest
    {
        if ($renter->type !== UserType::Renter) {
            throw NotARenter::make();
        }

        $manila = $preferredAt->setTimezone(ViewingSlots::TIMEZONE);

        if (! ViewingSlots::isSlot($manila)) {
            throw CannotRequestViewing::notASlot();
        }

        if ($manila->lt(ViewingSlots::now()->addHours(ViewingSlots::LEAD_HOURS))) {
            throw CannotRequestViewing::tooSoon(ViewingSlots::label(ViewingSlots::earliest()));
        }

        if ($manila->startOfDay()->gt(ViewingSlots::lastDay())) {
            throw CannotRequestViewing::tooFar(ViewingSlots::WINDOW_DAYS);
        }

        $message = $message === null ? null : trim($message);

        // Only ids go in (see ReserveSpace::handle()): the models passed in may be stale.
        return DB::transaction(
            fn (): ViewingRequest => $this->request($renter->id, $property->id, $preferredAt->utc(), $message === '' ? null : $message),
            self::ATTEMPTS,
        );
    }

    private function request(int $renterId, int $propertyId, CarbonImmutable $preferredAt, ?string $message): ViewingRequest
    {
        // 1. The renter lock, first: it serializes this renter's own requests.
        $renter = User::query()->whereKey($renterId)->lockForUpdate()->firstOrFail();

        // 2. The property must be published, not archived, of a verified account (a locking read, like ReserveSpace's).
        $property = Property::query()
            ->join('landlord_accounts', 'landlord_accounts.id', '=', 'properties.landlord_account_id')
            ->where('properties.id', $propertyId)
            ->where('properties.is_published', true)
            ->whereNull('properties.archived_at')
            ->where('landlord_accounts.is_verified', true)
            ->sharedLock()
            ->first(['properties.id', 'properties.landlord_account_id']);

        if ($property === null) {
            throw CannotRequestViewing::notListed();
        }

        // 3. The limits. Property first: it's the more specific message.
        $openIn = ViewingRequest::query()->openForRenter($renter)->sharedLock()->pluck('property_id');

        if ($openIn->filter(fn (mixed $id): bool => $id === $property->id)->count() >= ViewingRequest::MAX_OPEN_PER_PROPERTY) {
            throw CannotRequestViewing::inProperty();
        }

        if ($openIn->count() >= ViewingRequest::MAX_OPEN) {
            throw CannotRequestViewing::overall(ViewingRequest::MAX_OPEN);
        }

        return ViewingRequest::create([
            'property_id' => $property->id,
            'landlord_account_id' => $property->landlord_account_id,
            'renter_id' => $renter->id,
            'preferred_at' => $preferredAt,
            'message' => $message,
            'status' => ViewingRequestStatus::Requested,
        ]);
    }
}
