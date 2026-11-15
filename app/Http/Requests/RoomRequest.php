<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\RoomDetails;
use App\Enums\RoomType;
use App\Models\Property;
use App\Models\Room;
use App\Support\Pesos;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The room form, for adding a room to a property (`POST …/properties/{property}/rooms`) and
 * editing one (`PUT …/rooms/{room}`). Owners only, asked before validating (T7).
 * Money is typed in pesos and parsed from the string (App\Support\Pesos). `beds` (2 to 12)
 * is required for a new shared room, and when an edit switches a private room to shared
 * (the action checks that one: it knows the current type).
 */
final class RoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');
        $property = $this->route('property');

        if ($room instanceof Room) {
            return Gate::authorize('update', $room)->allowed();
        }

        return $property instanceof Property && Gate::authorize('create', [Room::class, $property])->allowed();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $creating = ! $this->route('room') instanceof Room;

        return [
            'name' => ['required', 'string', 'max:100'],
            'room_type' => ['required', Rule::enum(RoomType::class)],
            'monthly_rate' => ['required', 'string', 'regex:'.Pesos::PATTERN, $this->centavosBetween(1, __('The monthly rate must be more than ₱0 and at most ₱42,949,672.'))],
            'reservation_fee' => ['required', 'string', 'regex:'.Pesos::PATTERN, $this->centavosBetween(0, __('The reservation fee must be at most ₱42,949,672.'))],
            'deposit_months' => ['required', 'integer', 'between:0,3'],
            'advance_months' => ['required', 'integer', 'between:0,3'],
            'beds' => [$creating ? 'required_if:room_type,shared' : 'nullable', 'nullable', 'integer', 'between:2,12'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monthly_rate.regex' => __('Enter the rate in pesos, for example 6500 or 6500.50.'),
            'reservation_fee.regex' => __('Enter the fee in pesos, for example 1500 or 1500.50. Use 0 for no fee.'),
            'beds.required_if' => __('Say how many beds the room has (2 to 12).'),
            'beds.between' => __('A shared room has 2 to 12 beds.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'room_type' => __('room type'),
            'monthly_rate' => __('monthly rate'),
            'reservation_fee' => __('reservation fee'),
            'deposit_months' => __('deposit'),
            'advance_months' => __('advance'),
        ];
    }

    public function details(): RoomDetails
    {
        $type = RoomType::from($this->string('room_type')->toString());

        return new RoomDetails(
            name: $this->string('name')->toString(),
            type: $type,
            monthlyRate: Pesos::toCentavos($this->string('monthly_rate')->toString()),
            reservationFee: Pesos::toCentavos($this->string('reservation_fee')->toString()),
            depositMonths: $this->integer('deposit_months'),
            advanceMonths: $this->integer('advance_months'),
            beds: $type === RoomType::Shared && $this->filled('beds') ? $this->integer('beds') : null,
        );
    }

    /** A rule for a pesos string whose centavos must be in `[$min, MAX_CENTAVOS]`. */
    private function centavosBetween(int $min, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($min, $message): void {
            if (! is_string($value) || ! Pesos::matches($value)) {
                return; // the regex rule has said so
            }

            $centavos = Pesos::toCentavos($value);

            if ($centavos < $min || $centavos > Pesos::MAX_CENTAVOS) {
                $fail($message);
            }
        };
    }
}
