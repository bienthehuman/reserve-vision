<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ViewingRequest;
use App\Support\Viewings\ViewingSlots;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape only: a property, a date, a time and an optional message. The rules (the slots, the lead time,
 * the limits, whether the property is listed) are RequestViewing's, so they hold for every caller.
 */
final class RequestViewingRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'property_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'message' => ['nullable', 'string', 'max:'.ViewingRequest::MAX_TEXT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => __('day'),
            'time' => __('time'),
            'message' => __('message'),
        ];
    }

    public function propertyId(): int
    {
        return $this->integer('property_id');
    }

    /** The chosen Manila moment. A date and time that are not a real one can't get here: the rules above refuse them. */
    public function preferredAt(): CarbonImmutable
    {
        return ViewingSlots::at($this->string('date')->toString(), $this->string('time')->toString())
            ?? throw new \LogicException('date and time were validated');
    }

    public function note(): ?string
    {
        $message = $this->input('message');

        return is_string($message) ? $message : null;
    }
}
