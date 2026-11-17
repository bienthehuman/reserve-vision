<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ViewingRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * The optional note a landlord member sends with accepting or declining a viewing (slice 6.2). The
 * policy is asked here, before validation (a request validates before its controller runs, so a renter
 * gets 403 and never a validation message: T7).
 */
final class ViewingNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::authorize('answer', $this->viewing())->allowed();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:'.ViewingRequest::MAX_TEXT],
        ];
    }

    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) ? $note : null;
    }

    public function viewing(): ViewingRequest
    {
        $viewing = $this->route('viewing');

        return $viewing instanceof ViewingRequest ? $viewing : throw new LogicException('No viewing request on the route.');
    }
}
