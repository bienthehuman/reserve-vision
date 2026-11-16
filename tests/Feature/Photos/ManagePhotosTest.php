<?php

declare(strict_types=1);

use App\Enums\LandlordRole;
use App\Enums\PhotoStatus;
use App\Jobs\ProcessPropertyPhoto;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// Slice 4.2: the owner describes, reorders, deletes and re-uploads photos; a caretaker looks.

beforeEach(function (): void {
    fakeMediaDisks();
});

/**
 * An owner, their property, and `$count` ready photos in order, with files on the public disk.
 *
 * @return array{0: User, 1: Property, 2: list<PropertyPhoto>}
 */
function ownerWithPhotos(int $count = 3): array
{
    [$owner, $property] = ownerWithRoom();
    $photos = [];

    foreach (range(1, $count) as $sort) {
        $photos[] = $photo = PropertyPhoto::factory()->for($property)->create(['sort' => $sort]);
        Storage::disk('photos')->put($photo->thumbPath(), "thumb {$sort}");
        Storage::disk('photos')->put($photo->fullPath(), "full {$sort}");
    }

    return [$owner, $property, $photos];
}

/** The property's photo ids in their order. */
function photoOrder(Property $property): array
{
    return PropertyPhoto::query()->where('property_id', $property->id)->orderBy('sort')->pluck('id')->all();
}

// ----------------------------------------------------------------- the page

it('shows the photos in order with their URLs and default descriptions', function (): void {
    [$owner, $property, [$first, $second]] = ownerWithPhotos(2);
    $second->update(['alt' => 'Bunk beds by the window']);
    $processing = PropertyPhoto::factory()->for($property)->processing()->create(['sort' => 3]);
    $failed = PropertyPhoto::factory()->for($property)->failed()->create(['sort' => 4]);

    $this->actingAs($owner)->get("/landlord/properties/{$property->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('property.can_manage_photos', true)
        ->where('property.photo_limit', 12)
        ->has('photos', 4)
        ->where('photos.0', [
            'id' => $first->id, 'status' => 'ready', 'position' => 1, 'alt' => null, 'alt_text' => 'Photo 1 of Alpha Home',
            'thumb_url' => "/media/photos/{$first->path}-thumb.webp", 'full_url' => "/media/photos/{$first->path}-full.webp",
            'width' => 1600, 'height' => 1200,
        ])
        ->where('photos.1.alt', 'Bunk beds by the window')->where('photos.1.alt_text', 'Bunk beds by the window')
        ->where('photos.2.id', $processing->id)->where('photos.2.status', 'processing')->where('photos.2.thumb_url', null)
        ->where('photos.3.id', $failed->id)->where('photos.3.status', 'failed')->where('photos.3.thumb_url', null)->where('photos.3.alt_text', 'Photo 4 of Alpha Home'));
});

it('answers a poll for the photos alone', function (): void {
    [$owner, $property] = ownerWithPhotos(1);

    $version = $this->actingAs($owner)->get("/landlord/properties/{$property->id}")->viewData('page')['version'];

    $this->actingAs($owner)->withHeaders([
        'X-Inertia' => 'true', 'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'Landlord/Properties/Show', 'X-Inertia-Partial-Data' => 'photos',
    ])->get("/landlord/properties/{$property->id}")
        ->assertOk()
        ->assertJsonCount(1, 'props.photos')
        ->assertJsonMissingPath('props.rooms')
        ->assertJsonMissingPath('props.property');
});

it('shows a caretaker the same photos, read-only', function (): void {
    [, $property] = ownerWithPhotos(2);
    $caretaker = landlordUserOf($property->landlord_account_id, LandlordRole::Caretaker);

    $this->actingAs($caretaker)->get("/landlord/properties/{$property->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('property.can_manage_photos', false)->has('photos', 2)->where('photos.0.status', 'ready'));
});

// ------------------------------------------------------------- the file URLs

it('serves a resized photo at /media/photos on the app\'s own origin, cacheable for good', function (): void {
    [, , [$photo]] = ownerWithPhotos(1);

    // No login: like the production bucket, the files are public and the path is the secret.
    $response = $this->get($photo->thumb_url)->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/webp')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')->toContain('immutable')->toContain('public')
        ->and($response->streamedContent())->toBe('thumb 1')
        // The CSP needs no new source for it.
        ->and($response->headers->get('Content-Security-Policy'))->toContain("img-src 'self'");

    $this->get($photo->full_url)->assertOk();
});

it('serves nothing else from there', function (string $path): void {
    [, , [$photo]] = ownerWithPhotos(1);
    Storage::disk('photos')->put('doctor/probe.txt', 'x');
    Storage::disk('photos')->put('properties/notes.txt', 'x');

    $this->get('/media/photos/'.str_replace('{path}', $photo->path, $path))->assertNotFound();
})->with([
    'a file that is not there' => ['properties/00000000-0000-4000-8000-000000000000-thumb.webp'],
    'the stem alone' => ['{path}'],
    'another kind of file' => ['properties/notes.txt'],
    'another folder' => ['doctor/probe.txt'],
    'a way out of the disk' => ['properties/../../private/proofs/uploads/tmp/x'],
]);

it('gives the same relative URL for the production disk, unless AWS_PHOTOS_URL names another origin', function (): void {
    $photos = function (array $env): array {
        foreach ($env as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            return (require config_path('filesystems.php'))['disks']['photos'];
        } finally {
            foreach (array_keys($env) as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    };

    expect($photos(['MEDIA_STORAGE' => 's3'])['url'])->toBe('/media/photos')
        ->and($photos(['MEDIA_STORAGE' => 's3', 'AWS_PHOTOS_URL' => 'https://cdn.example.com/photos'])['url'])->toBe('https://cdn.example.com/photos')
        ->and($photos(['MEDIA_STORAGE' => 'local'])['url'])->toBe('/media/photos');
});

// ----------------------------------------------------------------- describe

it('saves a description, and an empty one goes back to the default', function (): void {
    [$owner, $property, [$photo]] = ownerWithPhotos(1);

    $this->actingAs($owner)->patch("/landlord/photos/{$photo->id}", ['alt' => "  Bunk beds   by the\twindow "])
        ->assertRedirect("/landlord/properties/{$property->id}")->assertSessionHas('status', 'The description is saved.');
    expect($photo->refresh()->alt)->toBe('Bunk beds by the window')
        ->and($photo->altText($property->name))->toBe('Bunk beds by the window');

    $this->actingAs($owner)->patch("/landlord/photos/{$photo->id}", ['alt' => 'Bunk beds by the window'])->assertRedirect(); // no change, no row
    $this->actingAs($owner)->patch("/landlord/photos/{$photo->id}", ['alt' => ''])->assertRedirect();
    expect($photo->refresh()->alt)->toBeNull()
        ->and($photo->altText($property->name))->toBe('Photo 1 of Alpha Home');

    $audit = auditRows('photo.described');
    expect($audit)->toHaveCount(2)
        ->and(json_decode($audit[0]->before, true))->toBe(['alt' => null])
        ->and(json_decode($audit[0]->after, true))->toBe(['alt' => 'Bunk beds by the window'])
        ->and(json_decode($audit[1]->after, true))->toBe(['alt' => null]);
});

it('limits a description to 160 characters', function (): void {
    [$owner, , [$photo]] = ownerWithPhotos(1);

    $this->actingAs($owner)->patch("/landlord/photos/{$photo->id}", ['alt' => str_repeat('a', 161)])->assertSessionHasErrors('alt');
    $this->actingAs($owner)->patch("/landlord/photos/{$photo->id}", ['alt' => str_repeat('a', 160)])->assertSessionHasNoErrors();
});

// ------------------------------------------------------------------ reorder

it('moves a photo up and down; the first is the cover', function (): void {
    [$owner, $property, [$a, $b, $c]] = ownerWithPhotos(3);

    $this->actingAs($owner)->post("/landlord/photos/{$c->id}/move", ['direction' => 'up'])
        ->assertRedirect("/landlord/properties/{$property->id}")->assertSessionHas('status', 'The photo is now number 2.');
    expect(photoOrder($property))->toBe([$a->id, $c->id, $b->id]);

    $this->actingAs($owner)->post("/landlord/photos/{$c->id}/move", ['direction' => 'up'])->assertSessionHas('status', 'The photo is now the cover.');
    expect(photoOrder($property))->toBe([$c->id, $a->id, $b->id]);

    $this->actingAs($owner)->post("/landlord/photos/{$c->id}/move", ['direction' => 'down'])->assertRedirect();
    expect(photoOrder($property))->toBe([$a->id, $c->id, $b->id])
        ->and(PropertyPhoto::query()->where('property_id', $property->id)->orderBy('sort')->pluck('sort')->all())->toBe([1, 2, 3]);

    // One row per move, on the photo that was moved.
    $audit = auditRows('photo.moved');
    expect($audit)->toHaveCount(3)
        ->and($audit->pluck('subject_id')->unique()->all())->toBe([$c->id])
        ->and([json_decode($audit[0]->before, true), json_decode($audit[0]->after, true)])->toBe([['sort' => 3], ['sort' => 2]]);

    $this->actingAs($owner)->get("/landlord/properties/{$property->id}")
        ->assertInertia(fn ($page) => $page->where('photos.0.id', $a->id)->where('photos.0.position', 1)->where('photos.1.id', $c->id));
});

it('does nothing at either end of the list', function (): void {
    [$owner, $property, [$a, $b]] = ownerWithPhotos(2);

    $this->actingAs($owner)->post("/landlord/photos/{$a->id}/move", ['direction' => 'up'])->assertRedirect();
    $this->actingAs($owner)->post("/landlord/photos/{$b->id}/move", ['direction' => 'down'])->assertRedirect();

    expect(photoOrder($property))->toBe([$a->id, $b->id])->and(auditRows())->toBeEmpty();
});

it('needs a direction it knows', function (): void {
    [$owner, , [$a]] = ownerWithPhotos(2);

    $this->actingAs($owner)->post("/landlord/photos/{$a->id}/move", ['direction' => 'sideways'])->assertSessionHasErrors('direction');
    $this->actingAs($owner)->post("/landlord/photos/{$a->id}/move")->assertSessionHasErrors('direction');
});

it('moves only within the photo\'s own property', function (): void {
    [$owner, $property, [$a, $b]] = ownerWithPhotos(2);
    $other = Property::factory()->create(['landlord_account_id' => $property->landlord_account_id]);
    $elsewhere = PropertyPhoto::factory()->for($other)->create(['sort' => 1]);

    $this->actingAs($owner)->post("/landlord/photos/{$b->id}/move", ['direction' => 'up'])->assertRedirect();

    expect(photoOrder($property))->toBe([$b->id, $a->id])->and($elsewhere->refresh()->sort)->toBe(1);
});

// ------------------------------------------------------------------- delete

it('deletes the row and both files, and closes the gap in the order', function (): void {
    [$owner, $property, [$a, $b, $c]] = ownerWithPhotos(3);
    Storage::disk('proofs')->put($b->uploadPath(), 'a leftover upload');

    $this->actingAs($owner)->delete("/landlord/photos/{$b->id}")
        ->assertRedirect("/landlord/properties/{$property->id}")->assertSessionHas('status', 'The photo is deleted.');

    expect(PropertyPhoto::query()->whereKey($b->id)->exists())->toBeFalse()
        ->and(Storage::disk('photos')->allFiles())->toEqualCanonicalizing([$a->thumbPath(), $a->fullPath(), $c->thumbPath(), $c->fullPath()])
        ->and(Storage::disk('proofs')->allFiles())->toBeEmpty()
        ->and(photoOrder($property))->toBe([$a->id, $c->id])
        ->and($c->refresh()->sort)->toBe(2);

    $audit = auditRows('photo.deleted');
    expect($audit)->toHaveCount(1)
        ->and($audit->first()->subject_id)->toBe($b->id)
        ->and(json_decode($audit->first()->before, true))->toEqual(['path' => $b->path, 'alt' => null, 'sort' => 2, 'status' => 'ready'])
        ->and(auditRows())->toHaveCount(1);
});

it('deletes the cover: the next photo becomes it', function (): void {
    [$owner, $property, [$a, $b]] = ownerWithPhotos(2);

    $this->actingAs($owner)->delete("/landlord/photos/{$a->id}")->assertRedirect();

    expect($b->refresh()->sort)->toBe(1);
    $this->actingAs($owner)->get("/landlord/properties/{$property->id}")->assertInertia(fn ($page) => $page->has('photos', 1)->where('photos.0.id', $b->id)->where('photos.0.position', 1));
});

it('deletes a processing photo with its waiting upload, and a failed one', function (): void {
    [$owner, $property] = ownerWithRoom();
    $processing = PropertyPhoto::factory()->for($property)->processing()->create(['sort' => 1]);
    $failed = PropertyPhoto::factory()->for($property)->failed()->create(['sort' => 2]);
    Storage::disk('proofs')->put($processing->uploadPath(), photoBytes());

    $this->actingAs($owner)->delete("/landlord/photos/{$processing->id}")->assertRedirect();
    $this->actingAs($owner)->delete("/landlord/photos/{$failed->id}")->assertRedirect();

    expect(PropertyPhoto::query()->count())->toBe(0)->and(Storage::disk('proofs')->allFiles())->toBeEmpty();
});

// -------------------------------------------------------------------- retry

it('uploads a failed photo again: same place and description, a new path, processing', function (): void {
    Queue::fake();
    [$owner, $property, [$a]] = ownerWithPhotos(1);
    $failed = PropertyPhoto::factory()->for($property)->failed()->create(['sort' => 2, 'alt' => 'The kitchen']);
    $oldPath = $failed->path;

    $this->actingAs($owner)->post("/landlord/photos/{$failed->id}/retry", ['photo' => photoUpload()])
        ->assertRedirect("/landlord/properties/{$property->id}")->assertSessionHas('status');

    $failed->refresh();
    expect($failed->status)->toBe(PhotoStatus::Processing)
        ->and($failed->path)->not->toBe($oldPath)
        ->and([$failed->sort, $failed->alt])->toBe([2, 'The kitchen'])
        ->and(Storage::disk('proofs')->allFiles())->toBe([$failed->uploadPath()])
        ->and(PropertyPhoto::query()->count())->toBe(2)
        ->and(auditRows('photo.retried'))->toHaveCount(1);
    Queue::assertPushed(ProcessPropertyPhoto::class, fn (ProcessPropertyPhoto $job): bool => $job->photoId === $failed->id);
});

it('ends ready after a retry with a good file', function (): void {
    [$owner, $property] = ownerWithRoom();
    $failed = PropertyPhoto::factory()->for($property)->failed()->create();

    $this->actingAs($owner)->post("/landlord/photos/{$failed->id}/retry", ['photo' => photoUpload()])->assertRedirect(); // the sync queue runs the job

    expect($failed->refresh()->status)->toBe(PhotoStatus::Ready)->and(Storage::disk('photos')->allFiles())->toHaveCount(2);
});

it('only retries a photo that failed, and validates the file like an upload', function (): void {
    [$owner, $property, [$ready]] = ownerWithPhotos(1);
    $failed = PropertyPhoto::factory()->for($property)->failed()->create(['sort' => 2]);

    $this->actingAs($owner)->postJson("/landlord/photos/{$ready->id}/retry", ['photo' => photoUpload()])
        ->assertStatus(422)->assertJson(['message' => 'Only a photo that failed can be uploaded again. To replace this one, delete it and add the new photo.']);
    $this->actingAs($owner)->post("/landlord/photos/{$failed->id}/retry", ['photo' => photoUpload('notes.jpg', 'not a photo')])->assertSessionHasErrors('photo');

    expect($ready->refresh()->status)->toBe(PhotoStatus::Ready)
        ->and($failed->refresh()->status)->toBe(PhotoStatus::Failed)
        ->and(Storage::disk('proofs')->allFiles())->toBeEmpty()
        ->and(auditRows())->toBeEmpty();
});
