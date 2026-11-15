<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\ChangeRoomRate;
use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The monthly rate is typed in pesos ("6500", "6,500" or "6500.50"), at most
 * two decimals, and stored as centavos. Parsed from the string, never through a float.
 * Who may do it is the policy's: asked here as well, because a request validates before
 * its controller runs and a caretaker must get 403, not a validation message (T7).
 */
final class ChangeRoomRateRequest extends FormRequest
{
    private const string PESOS = '/^\d{1,3}(,\d{3})*(\.\d{1,2})?$|^\d+(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        $room = $this->route('room');

        return $room instanceof Room && Gate::authorize('updateRate', $room)->allowed();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'monthly_rate' => ['required', 'string', 'regex:'.self::PESOS, function (string $attribute, mixed $value, \Closure $fail): void {
                $centavos = self::toCentavos(is_string($value) ? $value : '');

                if ($centavos < 1 || $centavos > ChangeRoomRate::MAX_CENTAVOS) {
                    $fail(__('The monthly rate must be more than ₱0 and at most ₱42,949,672.'));
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monthly_rate.regex' => __('Enter the rate in pesos, for example 6500 or 6500.50.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['monthly_rate' => __('monthly rate')];
    }

    public function centavos(): int
    {
        return self::toCentavos($this->string('monthly_rate')->toString());
    }

    /** "6,500.5" → 650050. Callers have checked the shape. */
    private static function toCentavos(string $pesos): int
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '', $pesos), 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
