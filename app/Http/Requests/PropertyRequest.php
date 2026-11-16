<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\PropertyDetails;
use App\Enums\GenderPolicy;
use App\Enums\PropertyKind;
use App\Models\Property;
use App\Support\Places;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The property form, for creating (`POST /landlord/properties`) and editing (`PUT …/{property}`).
 * Owners only, asked before validating so a caretaker gets 403, never a validation message (T7).
 * `city` is the city's slug from `database/data/places.php` (the province follows from it). `slug` is optional: left empty it is
 * generated from the name (create) or kept (update). Whether it may change is the action's call.
 */
final class PropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $property = $this->route('property');

        return ($property instanceof Property
            ? Gate::authorize('update', $property)
            : Gate::authorize('create', Property::class))->allowed();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $property = $this->route('property');

        return [
            'kind' => ['required', Rule::enum(PropertyKind::class)],
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('properties', 'slug')
                ->where('city_slug', $this->string('city')->toString())
                ->ignore($property instanceof Property ? $property->id : null)],
            'address' => ['nullable', 'string', 'max:255'],
            'barangay' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', Rule::in(Places::slugs())],
            'gender_policy' => ['required', Rule::enum(GenderPolicy::class)],
            'amenities' => ['present', 'array'],
            'amenities.*' => ['integer', 'exists:amenities,id'],
            'house_rules' => ['nullable', 'string', 'max:3000'],
            'payment_instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('Use lowercase letters, numbers and single hyphens, for example alpha-home.'),
            'slug.unique' => __('Another property in this city already uses that listing link.'),
            'barangay.required' => __('Enter the barangay.'),
            'city.required' => __('Choose a city.'),
            'city.in' => __('Choose a city from the list.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kind' => __('type'),
            'address' => __('street address'),
            'slug' => __('listing link'),
            'gender_policy' => __('who may stay'),
            'house_rules' => __('house rules'),
            'payment_instructions' => __('payment instructions'),
        ];
    }

    public function details(): PropertyDetails
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        $text = fn (string $key): ?string => isset($data[$key]) && (is_string($data[$key]) || is_int($data[$key]) || is_float($data[$key])) && (string) $data[$key] !== '' ? (string) $data[$key] : null;
        $amenities = is_array($data['amenities'] ?? null) ? $data['amenities'] : [];

        return new PropertyDetails(
            kind: PropertyKind::from($this->string('kind')->toString()),
            name: $this->string('name')->toString(),
            slug: $text('slug'),
            address: $text('address'),
            barangay: $this->string('barangay')->trim()->toString(),
            citySlug: $this->string('city')->toString(),
            genderPolicy: GenderPolicy::from($this->string('gender_policy')->toString()),
            amenityIds: array_values(array_unique(array_map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $amenities))),
            houseRules: $text('house_rules'),
            paymentInstructions: $text('payment_instructions'),
        );
    }
}
