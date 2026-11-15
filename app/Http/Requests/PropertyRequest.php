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
 * `city` is the city's slug from `database/data/places.php`. `slug` is optional: left empty it is
 * generated from the name (create) or kept (update). Whether it may change is the action's call.
 */
final class PropertyRequest extends FormRequest
{
    private const string COORDINATE = '/^-?\d{1,3}(\.\d{1,6})?$/';

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
            'city' => ['required', 'string', Rule::in(Places::slugs())],
            'barangay' => ['nullable', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:255'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'regex:'.self::COORDINATE, 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'regex:'.self::COORDINATE, 'between:-180,180'],
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
            'slug.unique' => __('Another property already uses that web address in this city.'),
            'lat.regex' => __('Enter the latitude as a number, for example 14.604200.'),
            'lng.regex' => __('Enter the longitude as a number, for example 120.982200.'),
            'lat.between' => __('The latitude must be between -90 and 90.'),
            'lng.between' => __('The longitude must be between -180 and 180.'),
            'lat.required_with' => __('Enter both the latitude and the longitude, or leave both empty.'),
            'lng.required_with' => __('Enter both the latitude and the longitude, or leave both empty.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kind' => __('type'),
            'slug' => __('web address'),
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
            citySlug: $this->string('city')->toString(),
            barangay: $text('barangay'),
            address: $this->string('address')->toString(),
            lat: ($lat = $text('lat')) === null ? null : Places::coordinate($lat),
            lng: ($lng = $text('lng')) === null ? null : Places::coordinate($lng),
            genderPolicy: GenderPolicy::from($this->string('gender_policy')->toString()),
            amenityIds: array_values(array_unique(array_map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $amenities))),
            houseRules: $text('house_rules'),
            paymentInstructions: $text('payment_instructions'),
        );
    }
}
