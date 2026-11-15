<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\RegistrationDetails;
use App\Enums\LandlordRole;
use App\Enums\UserType;
use App\Models\LandlordAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A landlord signs up (ACCESS.md, flow A): the user, their landlord account
 * (unverified, with a unique slug from the business name) and the owner
 * membership, all or nothing.
 */
final class RegisterLandlord
{
    /** Two sign-ups with the same business name can pick the same free slug at once. */
    private const ATTEMPTS = 3;

    public function handle(RegistrationDetails $details, string $businessName): User
    {
        $businessName = trim((string) preg_replace('/\s+/u', ' ', $businessName));

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): User => $this->register($details, $businessName));
            } catch (UniqueConstraintViolationException $e) {
                // Only a slug clash is worth retrying (a fresh slug is read each time);
                // a duplicate email means the address was taken in the meantime.
                if ($attempt >= self::ATTEMPTS || ! str_contains($e->getMessage(), 'slug')) {
                    throw $e;
                }
            }
        }
    }

    private function register(RegistrationDetails $details, string $businessName): User
    {
        $user = User::create([...$details->userAttributes(), 'type' => UserType::Landlord]);

        $account = LandlordAccount::create([
            'name' => $businessName,
            'slug' => $this->uniqueSlug($businessName),
            'contact_phone' => $details->phone,
            'is_verified' => false,
        ]);

        $account->members()->create([
            'user_id' => $user->id,
            'role' => LandlordRole::Owner,
        ]);

        return $user;
    }

    /** "santos-rentals", or "santos-rentals-2" when that is taken. */
    private function uniqueSlug(string $name): string
    {
        $base = rtrim(Str::limit(Str::slug(Str::ascii($name)), 80, ''), '-') ?: 'landlord';

        /** @var list<string> $taken */
        $taken = LandlordAccount::query()
            ->where('slug', $base)
            ->orWhere('slug', 'like', $base.'-%')
            ->pluck('slug')
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        for ($n = 2; ; $n++) {
            if (! in_array("{$base}-{$n}", $taken, true)) {
                return "{$base}-{$n}";
            }
        }
    }
}
