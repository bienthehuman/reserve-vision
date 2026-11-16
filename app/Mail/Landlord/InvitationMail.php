<?php

declare(strict_types=1);

namespace App\Mail\Landlord;

use App\Actions\CreateInvitation;
use App\Data\NewInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "You're invited to help manage <account>": the link to /invitations/{token} (slice 4.3).
 * Queued, like the auth mails. The plain token exists only in this mail, so the queued job
 * is encrypted: the jobs table never holds a usable link.
 */
final class InvitationMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $accountName,
        public readonly string $inviterName,
        public readonly string $roleLabel,
        public readonly string $url,
    ) {}

    public static function for(NewInvitation $created): self
    {
        $invitation = $created->invitation;

        return new self(
            accountName: $invitation->landlordAccount->name,
            inviterName: $invitation->inviter->full_name,
            roleLabel: $invitation->role->value,
            url: route('invitations.show', ['token' => $created->token]),
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':inviter invited you to :account on :app', [
            'inviter' => $this->inviterName,
            'account' => $this->accountName,
            'app' => config()->string('app.name'),
        ]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.landlord.invitation', with: ['days' => CreateInvitation::VALID_DAYS]);
    }
}
