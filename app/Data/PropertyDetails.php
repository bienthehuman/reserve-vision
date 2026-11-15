<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\GenderPolicy;
use App\Enums\PropertyKind;

/** What the owner types on the property form, validated and normalized (PropertyRequest). Input to CreateProperty and UpdateProperty. */
final readonly class PropertyDetails
{
    public function __construct(
        public PropertyKind $kind,
        public string $name,
        /** Null: generate it from the name (create) or keep it (update). */
        public ?string $slug,
        /** One of `database/data/places.php`. */
        public string $citySlug,
        public ?string $barangay,
        public string $address,
        /** DECIMAL(9,6) as text; null: the city's centre. */
        public ?string $lat,
        public ?string $lng,
        public GenderPolicy $genderPolicy,
        /** @var list<int> */
        public array $amenityIds,
        public ?string $houseRules,
        public ?string $paymentInstructions,
    ) {}
}
