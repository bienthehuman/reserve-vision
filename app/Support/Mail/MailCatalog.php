<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Mail\NoticeMail;
use App\Mail\Reservations\ConfirmationUndone;
use App\Mail\Reservations\HoldCreated;
use App\Mail\Reservations\HoldExpired;
use App\Mail\Reservations\ProofRejected;
use App\Mail\Reservations\ProofReminder;
use App\Mail\Reservations\ProofUploaded;
use App\Mail\Reservations\ReservationCancelled;
use App\Mail\Reservations\ReservationConfirmed;
use App\Mail\Reservations\ReservationDeclined;
use App\Mail\Reservations\ReservationWithdrawn;
use App\Mail\Viewings\ViewingAnswered;
use App\Mail\Viewings\ViewingCancelled;
use App\Mail\Viewings\ViewingRequested;

/**
 * Every status email, built from one reservation and one viewing request (slice 6.3): `/dev/mail` (development
 * only) renders them with demo data, and a test renders each one against real rows. The key is the URL segment.
 */
final class MailCatalog
{
    /**
     * @return array<string, array{label: string, to: string, mail: NoticeMail}>
     */
    public static function all(int $reservationId, int $viewingId): array
    {
        $renter = Format::t('The renter');
        $members = Format::t('The landlord\'s members');

        return [
            'hold-created' => ['label' => Format::t('Hold created'), 'to' => $renter, 'mail' => new HoldCreated($reservationId)],
            'proof-uploaded' => ['label' => Format::t('Proof uploaded'), 'to' => $members, 'mail' => new ProofUploaded($reservationId, '₱1,500', 'GC-4471 2098')],
            'proof-reminder-24' => ['label' => Format::t('Proof reminder, 24 hours'), 'to' => $members, 'mail' => new ProofReminder($reservationId, 24, Format::moment(now()->subHours(25)))],
            'proof-reminder-48' => ['label' => Format::t('Proof reminder, 48 hours'), 'to' => $members, 'mail' => new ProofReminder($reservationId, 48, Format::moment(now()->subHours(49)))],
            'proof-rejected' => ['label' => Format::t('Proof rejected'), 'to' => $renter, 'mail' => new ProofRejected($reservationId, 'The amount on the receipt is ₱1,000, not ₱1,500.')],
            'confirmed' => ['label' => Format::t('Confirmed'), 'to' => $renter, 'mail' => new ReservationConfirmed($reservationId)],
            'declined' => ['label' => Format::t('Declined'), 'to' => $renter, 'mail' => new ReservationDeclined($reservationId, 'The room was promised to a family member.')],
            'confirmation-undone' => ['label' => Format::t('Confirmation undone'), 'to' => $renter, 'mail' => new ConfirmationUndone($reservationId)],
            'withdrawn' => ['label' => Format::t('Withdrawn'), 'to' => $renter, 'mail' => new ReservationWithdrawn($reservationId, 'The building is closed for repairs.')],
            'cancelled' => ['label' => Format::t('Cancelled by the renter'), 'to' => $members, 'mail' => new ReservationCancelled($reservationId, hadProof: true)],
            'hold-expired' => ['label' => Format::t('Hold expired'), 'to' => $renter, 'mail' => new HoldExpired($reservationId)],
            'viewing-requested' => ['label' => Format::t('Viewing requested'), 'to' => $members, 'mail' => new ViewingRequested($viewingId)],
            'viewing-accepted' => ['label' => Format::t('Viewing accepted'), 'to' => $renter, 'mail' => new ViewingAnswered($viewingId, accepted: true)],
            'viewing-declined' => ['label' => Format::t('Viewing declined'), 'to' => $renter, 'mail' => new ViewingAnswered($viewingId, accepted: false)],
            'viewing-cancelled' => ['label' => Format::t('Viewing cancelled'), 'to' => $members, 'mail' => new ViewingCancelled($viewingId)],
        ];
    }
}
