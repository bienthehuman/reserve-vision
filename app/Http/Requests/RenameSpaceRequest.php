<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Space;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** The new label of a space. Owners only, asked before validating (T7). */
final class RenameSpaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $space = $this->route('space');

        return $space instanceof Space && Gate::authorize('update', $space)->allowed();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['label' => ['required', 'string', 'max:50']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['label' => __('name')];
    }
}
