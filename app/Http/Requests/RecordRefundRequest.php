<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** The refund record (slice 6.1): refunded yes or no, and an optional note. The policy is asked before validating. */
final class RecordRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        if (! $reservation instanceof Reservation) {
            throw new LogicException('No reservation on the route.');
        }

        return Gate::authorize('recordRefund', $reservation)->allowed();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'fee_refunded' => ['required', 'boolean'],
            'refund_note' => ['nullable', 'string', 'max:'.ReservationReasonRequest::MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fee_refunded.required' => __('Choose whether the fee was refunded.'),
        ];
    }

    public function note(): ?string
    {
        $note = $this->string('refund_note')->trim()->toString();

        return $note === '' ? null : $note;
    }
}
