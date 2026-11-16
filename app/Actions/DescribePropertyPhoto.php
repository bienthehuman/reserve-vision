<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\PropertyPhoto;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Sets a photo's `alt` text (owner only: PropertyPhotoPolicy::update, checked by the request).
 * Empty means "use the default": the pages then say "Photo N of <property>".
 * One audit row, `photo.described`; none if the text is the same.
 */
final class DescribePropertyPhoto
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PropertyPhoto $photo, ?string $alt): PropertyPhoto
    {
        $alt = trim((string) preg_replace('/\s+/u', ' ', (string) $alt));
        $alt = $alt === '' ? null : $alt;

        return DB::transaction(function () use ($photo, $alt): PropertyPhoto {
            $photo = PropertyPhoto::query()->whereKey($photo->getKey())->lockForUpdate()->firstOrFail();

            $before = ['alt' => $photo->alt];
            $photo->alt = $alt;
            $photo->save();

            $this->audit->record('photo.described', $photo, $before, ['alt' => $photo->alt]);

            return $photo;
        });
    }
}
