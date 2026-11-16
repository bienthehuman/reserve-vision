<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * The reason a landlord gives for rejecting a proof, declining or withdrawing (slice 6.1): required,
 * because the renter reads it. Who may is the policy's ability for the route, asked here because a
 * request validates before its controller runs (a caretaker withdrawing gets 403, never a validation message: T7).
 */
final class ReservationReasonRequest extends FormRequest
{
    /** The longest reason; a few sentences. */
    public const int MAX = 1000;

    /** Route name => ReservationPolicy ability. */
    private const array ABILITIES = [
        'landlord.reservations.reject' => 'rejectProof',
        'landlord.reservations.decline' => 'decline',
        'landlord.reservations.withdraw' => 'withdraw',
    ];

    public function authorize(): bool
    {
        $ability = self::ABILITIES[$this->route()?->getName() ?? ''] ?? throw new LogicException('ReservationReasonRequest on a route it has no ability for.');

        return Gate::authorize($ability, $this->reservation())->allowed();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:'.self::MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('Write a reason. The renter will see it.'),
        ];
    }

    public function reason(): string
    {
        return $this->string('reason')->trim()->toString();
    }

    public function reservation(): Reservation
    {
        $reservation = $this->route('reservation');

        return $reservation instanceof Reservation ? $reservation : throw new LogicException('No reservation on the route.');
    }
}
