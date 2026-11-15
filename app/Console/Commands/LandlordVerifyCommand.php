<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LandlordAccount;
use App\Models\Property;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The operator's way to verify a landlord account (docs/ACCESS.md): there is no
 * admin role in the web app. Runs as `app`: inserts and updates only, no DDL.
 */
final class LandlordVerifyCommand extends Command
{
    protected $signature = 'landlord:verify
        {slug? : The landlord account slug}
        {--revoke : Remove verification and unpublish every property of the account}
        {--list : List the accounts that are not verified}';

    protected $description = 'Verify or revoke a landlord account, or list the unverified ones';

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

        $account->forceFill(['is_verified' => true, 'verified_at' => now()])->save();

        $this->components->info("Verified [{$account->slug}] ({$account->name}). Its owner can now publish properties.");

        return self::SUCCESS;
    }

    private function revoke(LandlordAccount $account): int
    {
        $wasVerified = $account->is_verified;

        $unpublished = DB::transaction(function () use ($account): int {
            $account->forceFill(['is_verified' => false, 'verified_at' => null])->save();

            return Property::query()
                ->where('landlord_account_id', $account->id)
                ->where('is_published', true)
                ->update(['is_published' => false]);
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
