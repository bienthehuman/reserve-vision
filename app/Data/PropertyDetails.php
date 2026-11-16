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
        /** The street address: house or building number and street. The one optional part of an address. */
        public ?string $address,
        public string $barangay,
        /** One of `database/data/places.php`; it decides the province. */
        public string $citySlug,
        public GenderPolicy $genderPolicy,
        /** @var list<int> */
        public array $amenityIds,
        public ?string $houseRules,
        public ?string $paymentInstructions,
    ) {}
}
