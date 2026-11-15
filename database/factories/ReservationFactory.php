<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LandlordRole;
use App\Enums\PaymentProofStatus;
use App\Enums\ReservationStatus;
use App\Models\LandlordMember;
use App\Models\PaymentProof;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * Default: a live hold on the first free-for-all dates. Status states set the
 * status and the columns that status implies; they don't go through any action,
 * so they never check overlaps. Tests that need non-overlapping stays pass
 * `move_in`/`move_out`. `landlord_account_id` always comes from the space.
 *
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $moveIn = Date::now('Asia/Manila')->startOfDay()->addDays(fake()->numberBetween(7, 60));

        return [
            'space_id' => Space::factory(),
            'landlord_account_id' => fn (array $attributes): mixed => Space::query()
                ->whereKey($attributes['space_id'])->value('landlord_account_id'),
            'renter_id' => User::factory()->renter(),
            'move_in' => $moveIn->toDateString(),
            'move_out' => $moveIn->addMonthsNoOverflow(6)->toDateString(),
            'status' => ReservationStatus::Held,
            'reservation_fee' => 150000,
            'hold_expires_at' => now()->addDay(),
            'proof_rejections' => 0,
        ];
    }

    /** A live hold (the default). */
    public function held(): static
    {
        return $this->state([
            'status' => ReservationStatus::Held,
            'hold_expires_at' => now()->addDay(),
        ]);
    }

    /**
     * Still `held` in the database, but the hold has run out: it no longer
     * blocks the space, whether or not the ExpireHolds job has run.
     */
    public function expiredHold(): static
    {
        return $this->state([
            'status' => ReservationStatus::Held,
            'hold_expires_at' => now()->subHour(),
        ]);
    }

    /** With one pending payment proof. */
    public function proofUploaded(): static
    {
        return $this->state(['status' => ReservationStatus::ProofUploaded])
            ->afterCreating(fn (Reservation $r) => $this->proof($r, PaymentProofStatus::Pending));
    }

    /** Confirmed just now by an owner of the account, with the accepted proof. */
    public function confirmed(): static
    {
        return $this->state([
            'status' => ReservationStatus::Confirmed,
            'hold_expires_at' => null,
            'decided_at' => now(),
        ])->afterCreating(function (Reservation $r): void {
            $this->decidedByAnOwner($r);
            $this->proof($r, PaymentProofStatus::Accepted);
        });
    }

    /** Moved in a month ago; the stay runs for six months from then. */
    public function active(): static
    {
        $moveIn = Date::now('Asia/Manila')->startOfDay()->subMonth();

        return $this->confirmed()->state([
            'status' => ReservationStatus::Active,
            'move_in' => $moveIn->toDateString(),
            'move_out' => $moveIn->addMonthsNoOverflow(6)->toDateString(),
            'decided_at' => now()->subMonths(2),
        ]);
    }

    /** Stayed three months and left; both dates are in the past. */
    public function ended(): static
    {
        $moveIn = Date::now('Asia/Manila')->startOfDay()->subMonths(4);

        return $this->confirmed()->state([
            'status' => ReservationStatus::Ended,
            'move_in' => $moveIn->toDateString(),
            'move_out' => $moveIn->addMonthsNoOverflow(3)->toDateString(),
            'decided_at' => now()->subMonths(5),
        ]);
    }

    /** Past its hold, and the ExpireHolds job has run. */
    public function expired(): static
    {
        return $this->state([
            'status' => ReservationStatus::Expired,
            'hold_expires_at' => now()->subDay(),
        ]);
    }

    public function declined(): static
    {
        return $this->state([
            'status' => ReservationStatus::Declined,
            'decided_at' => now(),
            'decision_reason' => 'The room is no longer available.',
        ])->afterCreating(function (Reservation $r): void {
            $this->decidedByAnOwner($r);
            $this->proof($r, PaymentProofStatus::Pending);
        });
    }

    /** Cancelled by the renter. */
    public function cancelled(): static
    {
        return $this->state([
            'status' => ReservationStatus::Cancelled,
            'cancelled_at' => now(),
        ])->afterCreating(fn (Reservation $r) => $r->forceFill(['cancelled_by' => $r->renter_id])->save());
    }

    /** How many proofs the landlord has already rejected (0 to 2). */
    public function withProofRejections(int $count): static
    {
        return $this->state(['proof_rejections' => $count]);
    }

    private function proof(Reservation $reservation, PaymentProofStatus $status): PaymentProof
    {
        return PaymentProof::factory()->for($reservation)->state(['status' => $status])->create();
    }

    /**
     * Sets `decided_by` to an owner of the reservation's account, creating one
     * if the account has none, so it is always someone who could have decided.
     * (Done after creation: state closures see unresolved attributes.)
     */
    private function decidedByAnOwner(Reservation $reservation): void
    {
        $member = LandlordMember::query()
            ->where('landlord_account_id', $reservation->landlord_account_id)
            ->where('role', LandlordRole::Owner)
            ->first()
            ?? LandlordMember::factory()->owner()->create(['landlord_account_id' => $reservation->landlord_account_id]);

        $reservation->forceFill(['decided_by' => $member->user_id])->save();
    }
}
