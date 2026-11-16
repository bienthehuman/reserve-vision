<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\RecordRefund;
use App\Http\Controllers\Landlord\Concerns\AnswersDecisions;
use App\Http\Requests\RecordRefundRequest;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * PUT /landlord/reservations/{reservation}/refund: owner or caretaker. The request asks the policy before
 * it validates; whether the reservation can have a refund record is RecordRefund's rule.
 */
final class RecordRefundController
{
    use AnswersDecisions;

    public function __invoke(RecordRefundRequest $request, Reservation $reservation, RecordRefund $action): RedirectResponse|JsonResponse
    {
        $model = $action->handle($reservation, $request->boolean('fee_refunded'), $request->note());

        return $this->answer($request, $model, __('Refund record saved.'));
    }
}
