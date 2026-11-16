<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\MovePropertyPhoto;
use App\Models\PropertyPhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Which way a photo moves in the order. Owners only, asked before validating (T7). */
final class MovePropertyPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $photo = $this->route('photo');

        return $photo instanceof PropertyPhoto && Gate::authorize('reorder', [PropertyPhoto::class, $photo->property])->allowed();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['direction' => ['required', Rule::in([MovePropertyPhoto::UP, MovePropertyPhoto::DOWN])]];
    }
}
