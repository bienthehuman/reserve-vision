<?php

declare(strict_types=1);

namespace App\Data\Mail;

use App\Models\LandlordAccount;
use App\Models\ViewingRequest;
use App\Support\Mail\Format;

/**
 * The facts of a viewing request, read when the worker renders a viewing mail (see ReservationFacts). The
 * renter's name goes to the landlord's members; the address and landlord phone only to the renter, and only
 * in the mail about an accepted viewing (the renter's own page shows them then too).
 */
final readonly class ViewingFacts
{
    public function __construct(
        public int $viewingId,
        public string $property,
        public string $address,
        public string $renterName,
        public string $landlordName,
        public ?string $landlordPhone,
        public string $when,
        public ?string $message,
        public ?string $note,
    ) {}

    public static function of(ViewingRequest $viewing): self
    {
        $viewing->loadMissing(['property', 'renter']);
        $account = LandlordAccount::query()->findOrFail($viewing->landlord_account_id);

        return new self(
            viewingId: $viewing->id,
            property: $viewing->property->name,
            address: $viewing->property->full_address,
            renterName: $viewing->renter->full_name,
            landlordName: $account->name,
            landlordPhone: $account->contact_phone,
            when: Format::moment($viewing->preferred_at),
            message: $viewing->message,
            note: $viewing->landlord_note,
        );
    }
}
