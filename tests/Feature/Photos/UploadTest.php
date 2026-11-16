<?php

declare(strict_types=1);

use App\Actions\ResizePropertyPhoto;
use App\Enums\LandlordRole;
use App\Enums\PhotoStatus;
use App\Jobs\ProcessPropertyPhoto;
use App\Models\PropertyPhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// Slice 4.2: POST /landlord/properties/{property}/photos. One file per request, judged by its content.

beforeEach(function (): void {
    fakeMediaDisks();
});

it('stores the upload where the worker can read it, creates a processing photo and queues the resize', function (): void {
    Queue::fake();
    [$owner, $property] = ownerWithRoom();

    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/photos", ['photo' => photoUpload()])
        ->assertRedirect("/landlord/properties/{$property->id}")
        ->assertSessionHas('status');

    $photo = PropertyPhoto::query()->sole();
    expect($photo->status)->toBe(PhotoStatus::Processing)
        ->and($photo->property_id)->toBe($property->id)
        ->and($photo->landlord_account_id)->toBe($property->landlord_account_id)
        ->and($photo->sort)->toBe(1)
        ->and($photo->alt)->toBeNull()
        // A random stem, nothing of the file's name or the property's id.
        ->and($photo->path)->toMatch('#^properties/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#')
        ->and($photo->thumb_url)->toBeNull()
        ->and($photo->full_url)->toBeNull();

    // The original waits on the PRIVATE disk; nothing is public until the job has re-encoded it.
    expect(Storage::disk('proofs')->allFiles())->toBe([$photo->uploadPath()])
        ->and($photo->uploadPath())->toStartWith('uploads/tmp/')
        ->and(Storage::disk('photos')->allFiles())->toBeEmpty();

    Queue::assertPushed(ProcessPropertyPhoto::class, fn (ProcessPropertyPhoto $job): bool => $job->photoId === $photo->id);

    $audit = auditRows('photo.uploaded');
    expect($audit)->toHaveCount(1)
        ->and($audit->first()->subject_id)->toBe($photo->id)
        ->and($audit->first()->actor_id)->toBe($owner->id)
        ->and(json_decode($audit->first()->after, true))->toEqual(['property_id' => $property->id, 'path' => $photo->path, 'sort' => 1]);
});

it('keeps the temporary upload on a disk both containers reach, never a local-only one', function (): void {
    // In production `app` and `worker` are separate containers: a file on app's own disk (PHP's upload
    // directory, storage/, the `local` disk) doesn't exist for the worker. The upload disk is one of the
    // two media disks, which MEDIA_STORAGE=s3 puts on the object store, and it is the private one.
    expect(PropertyPhoto::UPLOAD_DISK)->toBe('proofs');

    $disks = function (string $mediaStorage): array {
        $before = getenv('MEDIA_STORAGE');
        putenv("MEDIA_STORAGE={$mediaStorage}");
        $_ENV['MEDIA_STORAGE'] = $_SERVER['MEDIA_STORAGE'] = $mediaStorage;

        try {
            return (require config_path('filesystems.php'))['disks'];
        } finally {
            $before === false ? putenv('MEDIA_STORAGE') : putenv("MEDIA_STORAGE={$before}");
            unset($_ENV['MEDIA_STORAGE'], $_SERVER['MEDIA_STORAGE']);
        }
    };

    $production = $disks('s3');
    expect($production[PropertyPhoto::UPLOAD_DISK]['driver'])->toBe('s3')
        ->and($production[PropertyPhoto::UPLOAD_DISK]['visibility'])->toBe('private')
        ->and($production[PropertyPhoto::DISK]['driver'])->toBe('s3')
        ->and($production[PropertyPhoto::UPLOAD_DISK]['bucket'] ?? 'proofs')->not->toBe($production[PropertyPhoto::DISK]['bucket'] ?? 'photos');

    // And the job reads only from that disk: with PHP's own temp file gone, the resize still works.
    [$owner, $property] = ownerWithRoom();
    Queue::fake();
    $upload = photoUpload();
    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/photos", ['photo' => $upload])->assertRedirect();
    @unlink($upload->getPathname());

    $photo = PropertyPhoto::query()->sole();
    (new ProcessPropertyPhoto($photo->id))->handle(app(ResizePropertyPhoto::class));

    expect($photo->refresh()->status)->toBe(PhotoStatus::Ready);
});

it('accepts JPEG, PNG and WebP by content, whatever the file is called', function (string $name, string $format): void {
    Queue::fake();
    [$owner, $property] = ownerWithRoom();

    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/photos", ['photo' => photoUpload($name, photoBytes(1000, 800, $format))])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect(PropertyPhoto::query()->count())->toBe(1);
})->with([
    'a JPEG' => ['room.jpg', 'jpeg'],
    'a PNG' => ['room.png', 'png'],
    'a WebP' => ['room.webp', 'webp'],
    'a PNG named .jpg' => ['room.jpg', 'png'],
    'a JPEG with no extension' => ['room', 'jpeg'],
]);

it('rejects a file that is not a photo it can use, and stores nothing', function (Closure $file, string $message): void {
    Queue::fake();
    [$owner, $property] = ownerWithRoom();

    $this->actingAs($owner)->from("/landlord/properties/{$property->id}")
        ->post("/landlord/properties/{$property->id}/photos", ['photo' => $file()])
        ->assertRedirect("/landlord/properties/{$property->id}")
        ->assertSessionHasErrors(['photo' => $message]);

    expect(PropertyPhoto::query()->count())->toBe(0)
        ->and(Storage::disk('proofs')->allFiles())->toBeEmpty()
        ->and(Storage::disk('photos')->allFiles())->toBeEmpty()
        ->and(auditRows())->toBeEmpty();
    Queue::assertNothingPushed();
})->with([
    'a text file renamed to .jpg (the browser says image/jpeg)' => [
        fn () => new UploadedFile(tap(tempnam(sys_get_temp_dir(), 'fake'), fn ($path) => file_put_contents($path, 'Just some notes, not a photo.')), 'bedroom.jpg', 'image/jpeg', null, true),
        'This file is not a JPEG, PNG or WebP photo.',
    ],
    'a PDF renamed to .png' => [fn () => photoUpload('scan.png', "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n"), 'This file is not a JPEG, PNG or WebP photo.'],
    'a GIF' => [fn () => photoUpload('room.gif', photoBytes(1000, 800, 'gif')), 'This file is not a JPEG, PNG or WebP photo.'],
    'a PNG header on a broken body' => [
        fn () => photoUpload('room.png', substr(photoBytes(1000, 800, 'png'), 0, 40).str_repeat('x', 2000)),
        'This photo could not be read. The file may be damaged; try exporting it again.',
    ],
    'smaller than 800 px on the longer side' => [
        fn () => photoUpload('small.jpg', photoBytes(799, 600)),
        'This photo is too small (799 × 600). It needs at least 800 pixels on its longer side.',
    ],
    'larger than 10 MB' => [
        fn () => photoUpload('huge.jpg', photoBytes().str_repeat('0', 10 * 1024 * 1024)),
        'This photo is larger than 10 MB. Choose a smaller one, or export it at a lower quality.',
    ],
    'more than 40 megapixels' => [
        // Only the header says 8000 × 5001: the size is read from it and refused before anything is decoded,
        // so the test needs no 40-megapixel image in memory (which once pushed CI's test process over the
        // queue worker's 128 MB stop, failing another test's `queue:work` with exit code 12).
        fn () => photoUpload('poster.png', "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNC5', 8000, 5001, 8, 2, 0, 0, 0).str_repeat("\0", 64)),
        'This photo has too many pixels (8000 × 5001). Resize it to 40 megapixels or fewer.',
    ],
    'no file at all' => [fn () => null, 'Choose a photo to upload.'],
    'text instead of a file' => [fn () => 'room.jpg', 'The photo did not arrive. Photos can be up to 10 MB each.'],
]);

it('accepts a photo of exactly 800 px on the longer side, in either direction', function (int $width, int $height): void {
    Queue::fake();
    [$owner, $property] = ownerWithRoom();

    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/photos", ['photo' => photoUpload('room.jpg', photoBytes($width, $height))])
        ->assertSessionHasNoErrors();
})->with([[800, 500], [500, 800]]);

it('allows 12 photos per property and refuses the thirteenth', function (): void {
    Queue::fake();
    [$owner, $property] = ownerWithRoom();
    foreach (range(1, 11) as $sort) {
        PropertyPhoto::factory()->for($property)->create(['sort' => $sort]);
    }

    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/photos", ['photo' => photoUpload()])->assertRedirect();
    expect(PropertyPhoto::query()->count())->toBe(12)
        ->and(PropertyPhoto::query()->orderByDesc('id')->firstOrFail()->sort)->toBe(12);

    $this->actingAs($owner)->postJson("/landlord/properties/{$property->id}/photos", ['photo' => photoUpload()])
        ->assertStatus(422)->assertJson(['message' => 'A property can have at most 12 photos. Delete one to add another.']);
    $this->actingAs($owner)->withHeaders(['X-Inertia' => 'true'])->from("/landlord/properties/{$property->id}")
        ->post("/landlord/properties/{$property->id}/photos", ['photo' => photoUpload()])
        ->assertStatus(303)->assertSessionHas('error');

    expect(PropertyPhoto::query()->count())->toBe(12)
        ->and(Storage::disk('proofs')->allFiles())->toHaveCount(1)
        ->and(auditRows('photo.uploaded'))->toHaveCount(1);
});

it('counts failed and processing photos towards the 12', function (): void {
    [$owner, $property] = ownerWithRoom();
    PropertyPhoto::factory()->for($property)->count(6)->failed()->create();
    PropertyPhoto::factory()->for($property)->count(6)->processing()->create();

    $this->actingAs($owner)->postJson("/landlord/properties/{$property->id}/photos", ['photo' => photoUpload()])->assertStatus(422);
});

it('lets several uploads follow each other, each taking the next place', function (): void {
    Queue::fake();
    [$owner, $property] = ownerWithRoom();

    foreach (['a.jpg', 'b.png', 'c.webp'] as $name) {
        $this->actingAs($owner)->post("/landlord/properties/{$property->id}/photos", ['photo' => photoUpload($name, photoBytes(900, 700, pathinfo($name, PATHINFO_EXTENSION) === 'jpg' ? 'jpeg' : pathinfo($name, PATHINFO_EXTENSION)))])
            ->assertSessionHasNoErrors();
    }

    expect(PropertyPhoto::query()->orderBy('id')->pluck('sort')->all())->toBe([1, 2, 3])
        ->and(PropertyPhoto::query()->pluck('path')->unique())->toHaveCount(3);
    Queue::assertPushed(ProcessPropertyPhoto::class, 3);
});

it('is for owners: a caretaker gets 403 before the file is looked at, a renter and a guest never get in', function (): void {
    Queue::fake();
    [$owner, $property] = ownerWithRoom();
    $caretaker = landlordUserOf($property->landlord_account_id, LandlordRole::Caretaker);

    $this->actingAs($caretaker)->post("/landlord/properties/{$property->id}/photos", ['photo' => photoUpload()])->assertForbidden();
    $this->actingAs($caretaker)->post("/landlord/properties/{$property->id}/photos", ['photo' => 'nonsense'])->assertForbidden();
    $this->actingAs(User::factory()->renter()->create())->post("/landlord/properties/{$property->id}/photos", ['photo' => photoUpload()])->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->post("/landlord/properties/{$property->id}/photos", ['photo' => photoUpload()])->assertRedirect('/login');

    expect(PropertyPhoto::query()->count())->toBe(0)->and(Storage::disk('proofs')->allFiles())->toBeEmpty();
});

it('resizes through the real database queue, as the worker does', function (): void {
    config(['queue.default' => 'database']);
    [$owner, $property] = ownerWithRoom();

    $this->actingAs($owner)->post("/landlord/properties/{$property->id}/photos", ['photo' => photoUpload('room.jpg', photoBytes(2400, 1800))])->assertRedirect();
    $photo = PropertyPhoto::query()->sole();
    expect($photo->status)->toBe(PhotoStatus::Processing);

    // The worker has no logged-in user.
    $this->app['auth']->forgetGuards();
    $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 1024])->assertExitCode(0);

    $photo->refresh();
    expect($photo->status)->toBe(PhotoStatus::Ready)
        ->and([$photo->width, $photo->height])->toBe([1600, 1200])
        ->and(Storage::disk('photos')->allFiles())->toEqualCanonicalizing([$photo->thumbPath(), $photo->fullPath()])
        ->and(Storage::disk('proofs')->allFiles())->toBeEmpty();
});
