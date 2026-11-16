<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Models\PropertyPhoto;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /media/photos/{path} when MEDIA_STORAGE=local (dev, tests, the dev container): streams a
 * resized photo from the photos disk, at the same URL Caddy serves the bucket on in production.
 * Public, like the bucket: the path is a random UUID, and only resized WebP files are here.
 * The route isn't registered when the disk is S3 (routes/web.php).
 */
final class ShowLocalPhoto
{
    public function __invoke(string $path): StreamedResponse
    {
        $disk = Storage::disk(PropertyPhoto::DISK);

        abort_unless(preg_match('#^properties/[0-9a-f-]{36}-(thumb|full)\.webp$#', $path) === 1 && $disk->exists($path), 404);

        return $disk->response($path, headers: ['Cache-Control' => PropertyPhoto::CACHE_CONTROL]);
    }
}
