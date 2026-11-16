<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PropertyDetails;
use App\Exceptions\Domain\CannotEditProperty;
use App\Models\LandlordAccount;
use App\Models\Property;
use App\Support\AuditLogger;
use App\Support\Places;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates a draft property for the current account (owner only: PropertyPolicy::create,
 * checked by the request). Not published, not archived. The slug is the one given or
 * generated from the name, unique per city: the database's unique key decides
 * (no scoped query can see other accounts' slugs), so a taken generated slug
 * gets "-2", "-3"… and a taken explicit one is refused. One audit row, `property.created`.
 */
final class CreateProperty
{
    /** How many suffixes to try before giving up on a generated slug. */
    private const int CANDIDATES = 50;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotEditProperty */
    public function handle(LandlordAccount $account, PropertyDetails $details): Property
    {
        $place = Places::find($details->citySlug) ?? throw new InvalidArgumentException("Unknown city [{$details->citySlug}].");

        return DB::transaction(function () use ($account, $details, $place): Property {
            $property = $this->insert($account, $details, $place);

            $property->amenities()->sync($details->amenityIds);

            $this->audit->record('property.created', $property, [], [
                ...$property->only(UpdateProperty::AUDITED),
                'amenities' => UpdateProperty::amenitySlugs($property),
            ]);

            return $property;
        });
    }

    /**
     * @param  array{city: string, slug: string, province: string, rate: float, barangays: list<string>}  $place
     */
    private function insert(LandlordAccount $account, PropertyDetails $details, array $place): Property
    {
        $explicit = $details->slug !== null && $details->slug !== '';
        $base = Str::slug($explicit ? (string) $details->slug : $details->name);
        $base = $base === '' ? 'property' : $base;

        foreach (range(1, $explicit ? 1 : self::CANDIDATES) as $attempt) {
            $slug = $attempt === 1 ? $base : "{$base}-{$attempt}";

            try {
                // A savepoint, so the failed insert leaves the outer transaction usable.
                return DB::transaction(fn (): Property => Property::create([
                    'landlord_account_id' => $account->id,
                    'kind' => $details->kind,
                    'name' => $details->name,
                    'slug' => $slug,
                    'address' => $details->address,
                    'barangay' => $details->barangay,
                    'city' => $place['city'],
                    'city_slug' => $place['slug'],
                    'province' => $place['province'],
                    'gender_policy' => $details->genderPolicy,
                    'house_rules' => $details->houseRules,
                    'payment_instructions' => $details->paymentInstructions,
                    'is_published' => false,
                    'first_published_at' => null,
                    'archived_at' => null,
                ]));
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw CannotEditProperty::slugTaken();
    }
}
