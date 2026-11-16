<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PhotoStatus;
use App\Models\Concerns\BelongsToLandlordAccount;
use Carbon\CarbonImmutable;
use Database\Factories\PropertyPhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $property_id
 * @property int $landlord_account_id
 * @property string $path a random stem: "<path>-thumb.webp" and "<path>-full.webp" on the photos disk
 * @property string|null $alt
 * @property int|null $width of the full size, once ready
 * @property int|null $height
 * @property int $sort 1 is the cover
 * @property PhotoStatus $status
 * @property-read string|null $thumb_url
 * @property-read string|null $full_url
 * @property-read Property $property
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['property_id', 'landlord_account_id', 'path', 'alt', 'width', 'height', 'sort', 'status'])]
final class PropertyPhoto extends Model
{
    /** @use HasFactory<PropertyPhotoFactory> */
    use BelongsToLandlordAccount, HasFactory;

    /** The public disk the two WebP files are on (config/filesystems.php). */
    public const string DISK = 'photos';

    /**
     * Where an upload waits for the resize job: the PRIVATE disk, which `app` and `worker`
     * both reach in production (they are separate containers, so a local temp file
     * written by one doesn't exist for the other). Never the public bucket.
     */
    public const string UPLOAD_DISK = 'proofs';

    public const string UPLOAD_PREFIX = 'uploads/tmp/';

    /**
     * Options for writing a resized file: on S3 the object is stored with this Cache-Control, which
     * Caddy passes on. A path never gets other content (a re-upload gets a new one), so it can be
     * cached for good. The local driver ignores it; ShowLocalPhoto sends the same header.
     */
    public const array WRITE_OPTIONS = ['CacheControl' => self::CACHE_CONTROL];

    public const string CACHE_CONTROL = 'public, max-age=31536000, immutable';

    /** Photos per property, in any status. */
    public const int LIMIT = 12;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'sort' => 'integer',
            'status' => PhotoStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** A new unguessable stem. */
    public static function newPath(): string
    {
        return 'properties/'.Str::uuid()->toString();
    }

    public function thumbPath(): string
    {
        return "{$this->path}-thumb.webp";
    }

    public function fullPath(): string
    {
        return "{$this->path}-full.webp";
    }

    /** The original upload on UPLOAD_DISK, until the resize job has dealt with it. */
    public function uploadPath(): string
    {
        return self::UPLOAD_PREFIX.basename($this->path);
    }

    /** The owner's description, or "Photo 2 of Santos Bedspace House". */
    public function altText(string $propertyName): string
    {
        return $this->alt ?? __('Photo :n of :property', ['n' => $this->sort, 'property' => $propertyName]);
    }

    /**
     * The 480 px wide WebP, for whatever MEDIA_STORAGE is: `/media/photos/…` on the app's own
     * origin (a route in dev, Caddy in production), or AWS_PHOTOS_URL. Null until ready.
     *
     * @return Attribute<string|null, never>
     */
    protected function thumbUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->status === PhotoStatus::Ready ? Storage::disk(self::DISK)->url($this->thumbPath()) : null);
    }

    /**
     * The full size (at most 1600 px on the long side). Null until ready.
     *
     * @return Attribute<string|null, never>
     */
    protected function fullUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->status === PhotoStatus::Ready ? Storage::disk(self::DISK)->url($this->fullPath()) : null);
    }
}
