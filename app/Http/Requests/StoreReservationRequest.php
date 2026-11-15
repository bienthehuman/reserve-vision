<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape only: a date and a whole number. The rules (window, 1–12 months,
 * limits, free space) are ReserveSpace's, so they hold for every caller.
 */
final class StoreReservationRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'move_in' => ['required', 'date_format:Y-m-d'],
            'months' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'move_in' => __('move-in date'),
            'months' => __('lease length'),
        ];
    }

    public function moveIn(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('move_in')->toString())
            ?: throw new \LogicException('move_in was validated as Y-m-d');
    }

    public function months(): int
    {
        return $this->integer('months');
    }
}
