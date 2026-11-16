<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PropertyDetails;
use App\Exceptions\Domain\CannotEditProperty;
use App\Models\Property;
use App\Support\AuditLogger;
use App\Support\Places\AddressCopies;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Edits a property (owner only: PropertyPolicy::update, checked by the request).
 * Locks the row and re-reads it, so the audit row's "before" is what was really replaced.
 *
 * The slug and the city make the public URL (/listings/{city}/{slug}), so once the
 * property has been published (`first_published_at`) they are locked: a different
 * value is refused, the same one is fine; the barangay can still change within the city. Before that the slug can be edited (null keeps it).
 * One audit row, `property.updated`, with the changed fields only; none if nothing changed.
 * Fields not on the form (publishing, archiving, photos) are other actions' business.
 */
final class UpdateProperty
{
    /** The columns whose changes are audited. */
    public const array AUDITED = [
        'kind', 'name', 'slug', 'address', 'barangay', 'city', 'city_slug', 'province', 'city_id', 'barangay_id',
        'gender_policy', 'house_rules', 'payment_instructions',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws CannotEditProperty */
    public function handle(Property $property, PropertyDetails $details): Property
    {
        $copies = AddressCopies::of($details->barangay);

        return DB::transaction(function () use ($property, $details, $copies): Property {
            $property = Property::query()->whereKey($property->getKey())->lockForUpdate()->firstOrFail();

            $slug = $details->slug === null || $details->slug === '' ? $property->slug : $details->slug;

            if ($property->hasBeenPublished()) {
                // By slug: a property from before slice 4.4 may not be linked yet, and choosing its own city links it.
                if ($copies['city_slug'] !== $property->city_slug) {
                    throw CannotEditProperty::cityLocked();
                }

                if ($slug !== $property->slug) {
                    throw CannotEditProperty::slugLocked();
                }
            }

            $before = [...$property->only(self::AUDITED), 'amenities' => self::amenitySlugs($property)];

            $property->fill([
                'kind' => $details->kind,
                'name' => $details->name,
                'slug' => $slug,
                'address' => $details->address,
                ...$copies,
                'gender_policy' => $details->genderPolicy,
                'house_rules' => $details->houseRules,
                'payment_instructions' => $details->paymentInstructions,
            ]);

            try {
                DB::transaction(fn () => $property->save());
            } catch (UniqueConstraintViolationException) {
                throw CannotEditProperty::slugTaken();
            }

            $property->amenities()->sync($details->amenityIds);

            $this->audit->record('property.updated', $property, $before, [
                ...$property->only(self::AUDITED),
                'amenities' => self::amenitySlugs($property),
            ]);

            return $property;
        });
    }

    /**
     * The property's amenities as sorted slugs, the audit row's form of them.
     *
     * @return list<string>
     */
    public static function amenitySlugs(Property $property): array
    {
        return array_values($property->amenities()->orderBy('slug')->pluck('slug')->map(fn (mixed $slug): string => is_string($slug) ? $slug : '')->all());
    }
}
