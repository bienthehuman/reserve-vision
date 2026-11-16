<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\PropertyDetails;
use App\Enums\GenderPolicy;
use App\Enums\PropertyKind;
use App\Models\Barangay;
use App\Models\City;
use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * The property form, for creating (`POST /landlord/properties`) and editing (`PUT …/{property}`).
 * Owners only, asked before validating so a caretaker gets 403, never a validation message (T7).
 * The address is `city_id` and `barangay_id` from the places tables (slice 4.4): the barangay must be one of the city's,
 * and the province follows from the city; no place is ever taken as text. `slug` is optional: left empty it is
 * generated from the name (create) or kept (update). Whether it and the city may change is the action's call.
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
                ->where('city_slug', $this->city()->slug ?? '')
                ->ignore($property instanceof Property ? $property->id : null)],
            'address' => ['nullable', 'string', 'max:255'],
            'barangay_id' => ['required', 'integer', Rule::exists('barangays', 'id')],
            'city_id' => ['required', 'integer', Rule::exists('cities', 'id')],
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
            'barangay_id.required' => __('Choose the barangay.'),
            'barangay_id.integer' => __('Choose the barangay from the list.'),
            'barangay_id.exists' => __('Choose the barangay from the list.'),
            'city_id.required' => __('Choose the city or municipality.'),
            'city_id.integer' => __('Choose the city or municipality from the list.'),
            'city_id.exists' => __('Choose the city or municipality from the list.'),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['city_id', 'barangay_id'])) {
                return;
            }

            if ($this->barangay()?->city_id !== $this->city()?->id) {
                $validator->errors()->add('barangay_id', __("That barangay isn't in the city you chose. Choose the barangay again."));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kind' => __('type'),
            'address' => __('street address'),
            'barangay_id' => __('barangay'),
            'city_id' => __('city'),
            'slug' => __('listing link'),
            'gender_policy' => __('who may stay'),
            'house_rules' => __('house rules'),
            'payment_instructions' => __('payment instructions'),
        ];
    }

    /** The chosen city, if the id is one. */
    private function city(): ?City
    {
        $id = $this->input('city_id');

        return is_numeric($id) ? City::query()->find((int) $id) : null;
    }

    /** The chosen barangay with its city and province, if the id is one. */
    private function barangay(): ?Barangay
    {
        $id = $this->input('barangay_id');

        return is_numeric($id) ? Barangay::query()->with('city.province')->find((int) $id) : null;
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
            barangay: $this->barangay() ?? throw new LogicException('Validated without a barangay.'),
            genderPolicy: GenderPolicy::from($this->string('gender_policy')->toString()),
            amenityIds: array_values(array_unique(array_map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $amenities))),
            houseRules: $text('house_rules'),
            paymentInstructions: $text('payment_instructions'),
        );
    }
}
