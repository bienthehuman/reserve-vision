<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Rules\PropertyPhotoFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * One photo file, for a new photo (`POST …/properties/{property}/photos`) and for uploading a
 * failed one again (`POST …/photos/{photo}/retry`). Owners only, asked before validating (T7).
 * The page sends several chosen files as one request each.
 */
final class PropertyPhotoUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $photo = $this->route('photo');
        $property = $this->route('property');

        if ($photo instanceof PropertyPhoto) {
            return Gate::authorize('retry', $photo)->allowed();
        }

        return $property instanceof Property && Gate::authorize('upload', [PropertyPhoto::class, $property])->allowed();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['photo' => ['bail', 'required', new PropertyPhotoFile]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.required' => __('Choose a photo to upload.'),
            // PHP refused the upload before Laravel saw it (upload_max_filesize).
            'photo.uploaded' => __('This photo is larger than 10 MB. Choose a smaller one, or export it at a lower quality.'),
        ];
    }

    public function photo(): UploadedFile
    {
        $file = $this->file('photo');

        return $file instanceof UploadedFile ? $file : throw new LogicException('Read the photo only after validation.');
    }
}
