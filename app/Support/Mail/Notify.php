<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Mail\NoticeMail;
use App\Models\LandlordMember;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Mail;

/**
 * Where status emails are queued (slice 6.3). Every mail goes out **after commit**: the mailable is flagged
 * `afterCommit`, so a transaction that rolls back (or that is still open when a caller wraps the action in one)
 * queues nothing. Each recipient gets a mail of their own, so nobody sees another person's address.
 */
final class Notify
{
    public static function user(User $user, NoticeMail&Mailable $mail): void
    {
        Mail::to(new Address($user->email, $user->full_name))->queue($mail->afterCommit());
    }

    /** The renter, by id: the action has no renter model of its own after its transaction. */
    public static function renter(int $renterId, NoticeMail&Mailable $mail): void
    {
        self::user(User::query()->findOrFail($renterId), $mail);
    }

    /** Every member of the account, owner and caretakers: the same notice, one mail each. */
    public static function landlordMembers(int $accountId, NoticeMail&Mailable $mail): void
    {
        $members = LandlordMember::query()->with('user')->where('landlord_account_id', $accountId)->orderBy('id')->get();

        foreach ($members as $member) {
            self::user($member->user, clone $mail);
        }
    }
}
