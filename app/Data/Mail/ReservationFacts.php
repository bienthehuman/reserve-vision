<?php

declare(strict_types=1);

namespace App\Data\Mail;

use App\Models\LandlordAccount;
use App\Models\Reservation;
use App\Support\Listings\ListingCards;
use App\Support\Mail\Format;
use App\Support\Pesos;

/**
 * The plain-text facts of a reservation at the moment a mail was queued (slice 6.3): the mail says what was true
 * then, whatever happens before the worker renders it. Holds strings only, so a mail never carries a model (and
 * so nothing that isn't printed here can leak into it). The renter's name goes to the landlord, their phone and
 * email never do: those are on the landlord's reservation page, behind the login.
 */
final readonly class ReservationFacts
{
    public function __construct(
        public int $reservationId,
        public string $property,
        public string $room,
        public string $space,
        public string $moveIn,
        public string $moveOut,
        public string $fee,
        public string $renterName,
        public string $landlordName,
        public ?string $landlordPhone,
        public ?string $paymentInstructions,
        public ?string $holdEnds,
        public string $listingPath,
    ) {}

    public static function of(Reservation $reservation): self
    {
        $reservation->loadMissing(['space.room.property', 'renter']);
        $room = $reservation->space->room;
        $property = $room->property;
        $account = LandlordAccount::query()->findOrFail($reservation->landlord_account_id);

        return new self(
            reservationId: $reservation->id,
            property: $property->name,
            room: $room->name,
            space: $reservation->space->label,
            moveIn: Format::day($reservation->move_in),
            moveOut: Format::day($reservation->move_out),
            fee: Pesos::format($reservation->reservation_fee),
            renterName: $reservation->renter->full_name,
            landlordName: $account->name,
            landlordPhone: $account->contact_phone,
            paymentInstructions: $property->payment_instructions,
            holdEnds: $reservation->hold_expires_at === null ? null : Format::moment($reservation->hold_expires_at),
            listingPath: ListingCards::url($property),
        );
    }

    /**
     * The rows every reservation mail shows, in this order.
     *
     * @return array<string, string>
     */
    public function rows(): array
    {
        return [
            Format::t('Property') => $this->property,
            Format::t('Room') => $this->room === $this->space ? $this->room : "{$this->room}, {$this->space}",
            Format::t('Move in') => $this->moveIn,
            Format::t('Move out') => $this->moveOut,
            Format::t('Reservation fee') => $this->fee,
        ];
    }
}
