<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PropertyPhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** A photo's description (`alt` text); empty goes back to the default. Owners only, asked before validating (T7). */
final class DescribePropertyPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $photo = $this->route('photo');

        return $photo instanceof PropertyPhoto && Gate::authorize('update', $photo)->allowed();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['alt' => ['nullable', 'string', 'max:160']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['alt' => __('description')];
    }
}
