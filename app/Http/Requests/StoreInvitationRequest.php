<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LandlordInvitation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The shape only: who may be invited is CreateInvitation's rule. The role is always caretaker,
 * never taken from the request. Who may invite is the policy's, asked here because a request
 * validates before its controller runs (a caretaker gets 403, not a validation message: T7).
 */
final class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::authorize('create', LandlordInvitation::class)->allowed();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['email' => __('email')];
    }
}
