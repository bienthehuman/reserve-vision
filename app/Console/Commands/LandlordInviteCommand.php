<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateInvitation;
use App\Enums\LandlordRole;
use App\Exceptions\Domain\CannotInvite;
use App\Models\LandlordAccount;
use App\Models\LandlordMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * The operator's way to invite a caretaker when the email can't be relied on (docs/ACCESS.md):
 * creates the invitation in the name of the account's first owner and prints the link, which the
 * operator passes on by hand. No email is sent. The same rules as the members page
 * (CreateInvitation), audited with a null actor. CI's production run uses it too: there is no
 * mailbox to read there (e2e/landlord-phase4.spec.ts).
 */
final class LandlordInviteCommand extends Command
{
    protected $signature = 'landlord:invite
        {slug : The landlord account slug}
        {email : The email address to invite as a caretaker}';

    protected $description = 'Invite a caretaker to a landlord account and print the invitation link (no email is sent)';

    public function handle(CreateInvitation $createInvitation): int
    {
        $slug = $this->argument('slug');
        $email = $this->argument('email');

        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:255']])->fails()) {
            $this->components->error('Give the slug of a landlord account and a valid email address.');

            return self::FAILURE;
        }

        $account = LandlordAccount::query()->where('slug', $slug)->first();
        $owner = $account === null ? null : LandlordMember::query()
            ->where('landlord_account_id', $account->id)
            ->where('role', LandlordRole::Owner)
            ->oldest('id')
            ->first()?->user;

        if ($account === null || $owner === null) {
            $this->components->error("No landlord account with the slug [{$slug}] and an owner.");

            return self::FAILURE;
        }

        try {
            $created = $createInvitation->handle($account, $owner, $email);
        } catch (CannotInvite $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Invited {$created->invitation->email} to [{$account->slug}] as a caretaker, in the name of {$owner->full_name}. The link works for ".CreateInvitation::VALID_DAYS.' days:');
        $this->line(route('invitations.show', ['token' => $created->token]));

        return self::SUCCESS;
    }
}
