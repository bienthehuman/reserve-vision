<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Models\LandlordMember;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| Slice 6.3: overlapping runs of `reservations:remind-proofs` send each reminder once.
|
| `withoutOverlapping()` stops the scheduler from starting a second run, but it is not what the guarantee rests on
| (a manual run, a second scheduler, a lock that expired). RemindAboutProof locks the reservation row and claims
| (proof, threshold) in `proof_reminders`, whose unique key refuses the second claim. Here FOUR real artisan
| processes start at once on the same six waiting proofs, with the database queue so the mails can be counted:
| exactly six reminder rows, and exactly one queued mail per member per proof.
*/

const REMINDER_PROOFS = 6;
const REMINDER_PROCESSES = 4;
const REMINDER_ROUNDS = 3;

it('queues each reminder once when four runs start at the same moment', function (): void {
    for ($round = 1; $round <= REMINDER_ROUNDS; $round++) {
        DB::table('jobs')->delete();
        $proofIds = [];
        $members = 0;

        for ($i = 0; $i < REMINDER_PROOFS; $i++) {
            $room = Room::factory()->for(Property::factory()->published())->create();
            $reservation = Reservation::factory()->proofUploaded()->for($room->spaces()->sole(), 'space')->for(User::factory()->renter()->create(), 'renter')->create();
            landlordUserOf($reservation->landlord_account_id);
            landlordUserOf($reservation->landlord_account_id, LandlordRole::Caretaker);
            $proof = PaymentProof::query()->where('reservation_id', $reservation->id)->sole();
            $proof->update(['uploaded_at' => now()->subHours(25)]);
            $proofIds[] = $proof->id;
            $members += LandlordMember::query()->where('landlord_account_id', $reservation->landlord_account_id)->count();
        }

        $env = [...getenv(), 'APP_ENV' => 'testing', 'DB_DATABASE' => (string) config('database.connections.mysql.database'), 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array'];
        $processes = [];

        for ($p = 0; $p < REMINDER_PROCESSES; $p++) {
            $pipes = [];
            $handle = proc_open([PHP_BINARY, base_path('artisan'), 'reservations:remind-proofs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            expect($handle)->not->toBeFalse();
            $processes[] = [$handle, $pipes];
        }

        $output = [];
        foreach ($processes as [$handle, $pipes]) {
            $output[] = trim((string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($handle))->toBe(0, implode("\n", $output));
        }

        $reminders = DB::table('proof_reminders')->whereIn('payment_proof_id', $proofIds)->count();
        $queued = DB::table('jobs')->count();

        expect($reminders)->toBe(REMINDER_PROOFS, "round {$round}: one reminder row per proof\n".implode("\n", $output))
            ->and($queued)->toBe($members, "round {$round}: one queued mail per member per proof, not more\n".implode("\n", $output));
    }
});
