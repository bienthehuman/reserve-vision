<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LandlordAccount;
use App\Models\Property;
use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The operator's way to verify a landlord account (docs/ACCESS.md): there is no
 * admin role in the web app. Runs as `app`: inserts and updates only, no DDL.
 *
 * Every change is audited with a null actor (the operator): the account's
 * verification, and each property --revoke unpublishes. There is no landlord
 * user in a console command, so the isolation scope is inactive here.
 */
final class LandlordVerifyCommand extends Command
{
    protected $signature = 'landlord:verify
        {slug? : The landlord account slug}
        {--revoke : Remove verification and unpublish every property of the account}
        {--list : List the accounts that are not verified}';

    protected $description = 'Verify or revoke a landlord account, or list the unverified ones';

    public function __construct(private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $slug = $this->argument('slug');

        if ($this->option('list')) {
            if ($slug !== null || $this->option('revoke')) {
                $this->components->error('--list takes no slug and can\'t be combined with --revoke.');

                return self::FAILURE;
            }

            return $this->listUnverified();
        }

        if (! is_string($slug) || $slug === '') {
            $this->components->error('Give the slug of a landlord account, or use --list to see the unverified ones.');

            return self::FAILURE;
        }

        $account = LandlordAccount::query()->where('slug', $slug)->first();

        if ($account === null) {
            $this->components->error("No landlord account with the slug [{$slug}]. Run with --list to see the unverified accounts.");

            return self::FAILURE;
        }

        return $this->option('revoke') ? $this->revoke($account) : $this->verify($account);
    }

    private function verify(LandlordAccount $account): int
    {
        if ($account->is_verified) {
            $this->components->info("[{$account->slug}] is already verified (since {$account->verified_at?->toDateTimeString()} UTC). Nothing changed.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($account): void {
            $before = $account->only(['is_verified', 'verified_at']);
            $account->forceFill(['is_verified' => true, 'verified_at' => now()])->save();
            $this->audit->record('account.verified', $account, $before, $account->only(['is_verified', 'verified_at']));
        });

        $this->components->info("Verified [{$account->slug}] ({$account->name}). Its owner can now publish properties.");

        return self::SUCCESS;
    }

    private function revoke(LandlordAccount $account): int
    {
        $wasVerified = $account->is_verified;

        $unpublished = DB::transaction(function () use ($account): int {
            $before = $account->only(['is_verified', 'verified_at']);
            $account->forceFill(['is_verified' => false, 'verified_at' => null])->save();
            $this->audit->record('account.verification_revoked', $account, $before, $account->only(['is_verified', 'verified_at']));

            $properties = Property::query()
                ->where('landlord_account_id', $account->id)
                ->where('is_published', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($properties->isEmpty()) {
                return 0;
            }

            Property::query()
                ->whereKey($properties->modelKeys())
                ->update(['is_published' => false]);

            foreach ($properties as $property) {
                $this->audit->record('property.unpublished', $property, ['is_published' => true], ['is_published' => false]);
            }

            return $properties->count();
        });

        $this->components->info(
            ($wasVerified ? "Revoked verification of [{$account->slug}]" : "[{$account->slug}] was not verified")
            .". Unpublished {$unpublished} ".($unpublished === 1 ? 'property' : 'properties').'.',
        );

        return self::SUCCESS;
    }

    private function listUnverified(): int
    {
        $accounts = LandlordAccount::query()
            ->where('is_verified', false)
            ->withCount('properties')
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            $this->components->info('Every landlord account is verified.');

            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Name', 'Properties', 'Created'],
            $accounts->map(fn (LandlordAccount $a): array => [$a->slug, $a->name, $a->properties_count, $a->created_at->toDateString()])->all(),
        );

        return self::SUCCESS;
    }
}
