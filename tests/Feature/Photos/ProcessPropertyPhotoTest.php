<?php

declare(strict_types=1);

use App\Actions\DeletePropertyPhoto;
use App\Actions\ResizePropertyPhoto;
use App\Enums\PhotoStatus;
use App\Jobs\ProcessPropertyPhoto;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Support\Photos\UnreadablePhoto;
use Illuminate\Support\Facades\Storage;

// Slice 4.2: the queued resize. Reads the upload from temporary storage, writes the two WebP
// files to the public disk, marks the photo ready and removes the upload; on its final failure
// it marks the photo failed and keeps the row.

beforeEach(function (): void {
    fakeMediaDisks();
});

/** A processing photo whose upload is waiting on the private disk. */
function waitingPhoto(?string $bytes = null): PropertyPhoto
{
    $photo = PropertyPhoto::factory()->processing()->create();
    Storage::disk('proofs')->put($photo->uploadPath(), $bytes ?? photoBytes(2400, 1800));

    return $photo;
}

function runPhotoJob(PropertyPhoto $photo): void
{
    ProcessPropertyPhoto::dispatchSync($photo->id);
}

it('writes a 480 px thumbnail and a 1600 px full size as WebP, marks the photo ready and removes the upload', function (): void {
    $photo = waitingPhoto();

    runPhotoJob($photo);

    $photo->refresh();
    $disk = Storage::disk('photos');
    $thumb = getimagesizefromstring($disk->get($photo->thumbPath()));
    $full = getimagesizefromstring($disk->get($photo->fullPath()));

    expect($photo->status)->toBe(PhotoStatus::Ready)
        ->and([$photo->width, $photo->height])->toBe([1600, 1200])
        ->and($disk->allFiles())->toEqualCanonicalizing([$photo->thumbPath(), $photo->fullPath()])
        ->and([$thumb[0], $thumb[1], $thumb['mime']])->toBe([480, 360, 'image/webp'])
        ->and([$full[0], $full[1], $full['mime']])->toBe([1600, 1200, 'image/webp'])
        ->and(Storage::disk('proofs')->allFiles())->toBeEmpty()
        ->and($photo->thumb_url)->toBe("/media/photos/{$photo->path}-thumb.webp")
        ->and($photo->full_url)->toBe("/media/photos/{$photo->path}-full.webp")
        // The system's work: no audit row.
        ->and(auditRows())->toBeEmpty();
});

it('applies the EXIF orientation and leaves no EXIF or GPS in the public files', function (): void {
    $photo = waitingPhoto(jpegWithExif(orientation: 6, width: 2400, height: 1600));

    runPhotoJob($photo);

    $photo->refresh();
    expect([$photo->width, $photo->height])->toBe([1067, 1600]); // upright now

    foreach ([$photo->thumbPath(), $photo->fullPath()] as $path) {
        $webp = Storage::disk('photos')->get($path);
        expect($webp)->not->toContain('Exif')->not->toContain('EXIF')->not->toContain('GPS')
            ->and(photoColourAt($webp, 0.5, 0.25))->toBe('red');
    }
});

it('gives the same result when it runs twice', function (): void {
    $photo = waitingPhoto();
    $upload = Storage::disk('proofs')->get($photo->uploadPath());

    runPhotoJob($photo);
    $first = [Storage::disk('photos')->get($photo->thumbPath()), Storage::disk('photos')->get($photo->fullPath()), $photo->refresh()->only(['status', 'width', 'height', 'path'])];

    // Again as it is (ready, upload gone): nothing happens. And again as if the worker died before the
    // row was updated (still processing, upload still there): the same two files, the same row.
    runPhotoJob($photo);
    expect(Storage::disk('photos')->allFiles())->toHaveCount(2)->and($photo->refresh()->status)->toBe(PhotoStatus::Ready);

    PropertyPhoto::query()->whereKey($photo->id)->toBase()->update(['status' => 'processing', 'width' => null, 'height' => null]);
    Storage::disk('proofs')->put($photo->uploadPath(), $upload);
    runPhotoJob($photo);

    expect([Storage::disk('photos')->get($photo->thumbPath()), Storage::disk('photos')->get($photo->fullPath()), $photo->refresh()->only(['status', 'width', 'height', 'path'])])->toBe($first)
        ->and(Storage::disk('photos')->allFiles())->toHaveCount(2)
        ->and(Storage::disk('proofs')->allFiles())->toBeEmpty();
});

it('removes a leftover upload of a photo that is already ready', function (): void {
    $photo = PropertyPhoto::factory()->create();
    Storage::disk('proofs')->put($photo->uploadPath(), photoBytes());

    runPhotoJob($photo);

    expect(Storage::disk('proofs')->allFiles())->toBeEmpty()->and(Storage::disk('photos')->allFiles())->toBeEmpty();
});

it('fails at once when the upload does not decode: failed, upload removed, row kept', function (): void {
    $photo = waitingPhoto('not an image at all');

    runPhotoJob($photo);

    expect($photo->refresh()->status)->toBe(PhotoStatus::Failed)
        ->and($photo->thumb_url)->toBeNull()
        ->and(PropertyPhoto::query()->count())->toBe(1)
        ->and(Storage::disk('proofs')->allFiles())->toBeEmpty()
        ->and(Storage::disk('photos')->allFiles())->toBeEmpty();
});

it('fails when the upload is gone', function (): void {
    $photo = PropertyPhoto::factory()->processing()->create();

    expect(fn () => app(ResizePropertyPhoto::class)->handle($photo))->toThrow(UnreadablePhoto::class);

    runPhotoJob($photo);
    expect($photo->refresh()->status)->toBe(PhotoStatus::Failed);
});

it('tries three times, 10 and 60 seconds apart, after the upload has committed', function (): void {
    $job = new ProcessPropertyPhoto(1);

    expect($job->tries)->toBe(3)->and($job->backoff())->toBe([10, 60])->and($job->afterCommit)->toBeTrue();
});

it('on its final failure marks the photo failed, removes the upload and anything half-written, and keeps the row', function (): void {
    $photo = waitingPhoto();
    Storage::disk('photos')->put($photo->thumbPath(), 'half');

    // What the worker calls after the third try (storage away each time, say).
    (new ProcessPropertyPhoto($photo->id))->failed(new RuntimeException('Storage is away.'));

    expect($photo->refresh()->status)->toBe(PhotoStatus::Failed)
        ->and(Storage::disk('proofs')->allFiles())->toBeEmpty()
        ->and(Storage::disk('photos')->allFiles())->toBeEmpty();
});

it('lets a storage error through, so the queue tries again and the photo stays processing', function (): void {
    $photo = waitingPhoto();
    Storage::shouldReceive('disk')->with('proofs')->andThrow(new RuntimeException('Storage is away.'));

    expect(fn () => app(ResizePropertyPhoto::class)->handle($photo))->toThrow(RuntimeException::class, 'Storage is away.');
    expect(PropertyPhoto::query()->whereKey($photo->id)->value('status'))->toBe(PhotoStatus::Processing);
});

it('does not bring a failed photo back, or touch a ready one, when a late final failure arrives', function (): void {
    $ready = PropertyPhoto::factory()->create();
    Storage::disk('photos')->put($ready->thumbPath(), 'thumb');
    Storage::disk('photos')->put($ready->fullPath(), 'full');

    (new ProcessPropertyPhoto($ready->id))->failed(new RuntimeException('late'));

    expect($ready->refresh()->status)->toBe(PhotoStatus::Ready)->and(Storage::disk('photos')->allFiles())->toHaveCount(2);
});

it('does nothing for a photo deleted while it waited in the queue', function (): void {
    runPhotoJob(tap(PropertyPhoto::factory()->processing()->create())->delete());

    expect(Storage::disk('photos')->allFiles())->toBeEmpty();
});

it('removes what it wrote when the owner deletes the photo while it is being resized', function (): void {
    $photo = waitingPhoto();
    $stale = PropertyPhoto::query()->findOrFail($photo->id); // what the job loaded before the delete

    app(DeletePropertyPhoto::class)->handle($photo);
    Storage::disk('proofs')->put($stale->uploadPath(), photoBytes()); // the job had already read it

    app(ResizePropertyPhoto::class)->handle($stale);

    expect(PropertyPhoto::query()->count())->toBe(0)
        ->and(Storage::disk('photos')->allFiles())->toBeEmpty()
        ->and(Storage::disk('proofs')->allFiles())->toBeEmpty();
});

it('never upscales a small photo', function (): void {
    $photo = waitingPhoto(photoBytes(900, 600));

    runPhotoJob($photo);

    expect($photo->refresh()->only(['width', 'height']))->toBe(['width' => 900, 'height' => 600]);
});

it('works on a property of any account without a logged-in user (the worker)', function (): void {
    $photo = waitingPhoto();
    expect(auth()->check())->toBeFalse();

    runPhotoJob($photo);

    expect($photo->refresh()->status)->toBe(PhotoStatus::Ready)
        ->and(Property::query()->whereKey($photo->property_id)->exists())->toBeTrue();
});
