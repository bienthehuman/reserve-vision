<?php

declare(strict_types=1);

use App\Actions\UploadPaymentProof;
use App\Enums\PaymentProofStatus;
use App\Enums\ProofFileType;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Models\PaymentProof;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    fakeMediaDisks();
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/** POST the proof form for `$hold` as its renter, from the hold page. */
function sendProof(mixed $test, Reservation $hold, array $form): TestResponse
{
    return $test->actingAs($hold->renter)->from("/reservations/{$hold->id}")->post("/reservations/{$hold->id}/proofs", $form);
}

it('takes a proof on a live hold: stores the original bytes privately, adds a pending proof, moves the reservation to proof_uploaded', function (): void {
    $hold = liveHold(User::factory()->renter()->create());
    $bytes = jpegWithExif(6, 900, 600); // metadata and all: evidence is kept exactly as sent

    sendProof($this, $hold, proofForm(['proof' => proofUpload('IMG_2201.JPG', $bytes), 'amount' => '1,500.50', 'reference_no' => '  1009 482 771  ']))
        ->assertRedirect("/reservations/{$hold->id}")
        ->assertSessionHas('status', 'Proof sent. The landlord will check it and confirm your reservation.')
        ->assertSessionHasNoErrors();

    $proof = PaymentProof::query()->where('reservation_id', $hold->id)->sole();

    expect($hold->fresh()?->status)->toBe(ReservationStatus::ProofUploaded)
        ->and($proof->status)->toBe(PaymentProofStatus::Pending)
        ->and($proof->mime_type)->toBe(ProofFileType::Jpeg)
        ->and($proof->amount)->toBe(150050)
        ->and($proof->reference_no)->toBe('1009 482 771')
        ->and($proof->landlord_account_id)->toBe($hold->landlord_account_id)
        ->and($proof->uploaded_at->equalTo(now()))->toBeTrue()
        ->and($proof->path)->toMatch('#^proofs/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}\.jpg$#')
        ->and($proof->path)->not->toContain('IMG_2201')
        ->and(Storage::disk('proofs')->get($proof->path))->toBe($bytes)
        ->and(Storage::disk('photos')->allFiles())->toBeEmpty()
        ->and(auditRows())->toBeEmpty();
});

it('takes a PDF, a PNG and a WebP, and stores the type it detected, not the name\'s', function (string $name, Closure $bytes, ProofFileType $type): void {
    $hold = liveHold(User::factory()->renter()->create());

    sendProof($this, $hold, proofForm(['proof' => proofUpload($name, $bytes())]))->assertSessionHasNoErrors();

    $proof = PaymentProof::query()->where('reservation_id', $hold->id)->sole();
    expect($proof->mime_type)->toBe($type)->and($proof->path)->toEndWith('.'.$type->extension());
})->with([
    'a PDF called .pdf' => ['receipt.pdf', fn () => pdfBytes(2, objectStreams: true), ProofFileType::Pdf],
    'a PNG called .jpg' => ['receipt.jpg', fn () => photoBytes(300, 500, 'png'), ProofFileType::Png],
    'a WebP with no extension' => ['receipt', fn () => photoBytes(300, 500, 'webp'), ProofFileType::Webp],
]);

it('refuses a file by its content, and stores nothing', function (UploadedFile|string $file, string $message): void {
    $hold = liveHold(User::factory()->renter()->create());

    sendProof($this, $hold, proofForm(['proof' => $file]))->assertSessionHasErrors(['proof' => $message]);

    expect($hold->fresh()?->status)->toBe(ReservationStatus::Held)
        ->and(PaymentProof::query()->count())->toBe(0)
        ->and(Storage::disk('proofs')->allFiles())->toBeEmpty();
})->with([
    'a GIF' => fn () => [proofUpload('receipt.gif', photoBytes(300, 300, 'gif')), 'This file is not a JPEG, PNG, WebP or PDF. Upload a screenshot or photo of the receipt, or a PDF.'],
    'HTML called .png' => fn () => [proofUpload('receipt.png', '<html><script>alert(1)</script></html>'), 'This file is not a JPEG, PNG, WebP or PDF. Upload a screenshot or photo of the receipt, or a PDF.'],
    'SVG called .pdf' => fn () => [proofUpload('receipt.pdf', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'This file is not a JPEG, PNG, WebP or PDF. Upload a screenshot or photo of the receipt, or a PDF.'],
    'a damaged PNG' => fn () => [proofUpload('receipt.png', substr(photoBytes(300, 300, 'png'), 0, 60).str_repeat("\0", 300)), 'This image could not be read. The file may be damaged; take a new screenshot and try again.'],
    'a truncated PDF' => fn () => [proofUpload('receipt.pdf', substr(pdfBytes(), 0, 40)), 'This PDF could not be read. It may be damaged or password-protected; save it again without a password, or upload a screenshot.'],
    'over 5 MB' => fn () => [proofUpload('receipt.pdf', pdfBytes().str_repeat(' ', 5 * 1024 * 1024)), 'This file is larger than 5 MB. Take a screenshot of the receipt instead, or choose a smaller file.'],
    'no file' => fn () => ['', 'Choose the screenshot, photo or PDF of your receipt.'],
]);

it('needs an amount in pesos above zero and a reference number', function (array $fields, array $errors): void {
    $hold = liveHold(User::factory()->renter()->create());

    sendProof($this, $hold, proofForm($fields))->assertSessionHasErrors($errors);

    expect(PaymentProof::query()->count())->toBe(0);
})->with([
    'no amount' => [['amount' => ''], ['amount' => 'Enter the amount you paid.']],
    'words' => [['amount' => 'fifteen hundred'], ['amount' => 'Enter the amount in pesos, for example 1500 or 1500.50.']],
    'three decimals' => [['amount' => '1500.505'], ['amount' => 'Enter the amount in pesos, for example 1500 or 1500.50.']],
    'zero' => [['amount' => '0'], ['amount' => 'Enter the amount you paid, more than ₱0.']],
    'twenty digits' => [['amount' => '99999999999999999999'], ['amount' => 'Enter the amount in pesos, for example 1500 or 1500.50.']],
    'no reference' => [['reference_no' => ''], ['reference_no' => 'Enter the reference number from your receipt.']],
    'a long reference' => [['reference_no' => str_repeat('9', 65)], ['reference_no']],
]);

it('refuses a second proof while the first waits for review', function (): void {
    $hold = liveHold(User::factory()->renter()->create());
    sendProof($this, $hold, proofForm())->assertSessionHasNoErrors();

    sendProof($this, $hold, proofForm())
        ->assertRedirect("/reservations/{$hold->id}")
        ->assertSessionHas('error', InvalidTransition::between(ReservationStatus::ProofUploaded, ReservationStatus::ProofUploaded)->getMessage());

    expect(PaymentProof::query()->count())->toBe(1)->and(Storage::disk('proofs')->allFiles())->toHaveCount(1);
});

it('answers 404 for another renter\'s reservation, before looking at the form, and 403 for a landlord', function (): void {
    $hold = liveHold(User::factory()->renter()->create());
    $other = User::factory()->renter()->create();

    $this->actingAs($other)->post("/reservations/{$hold->id}/proofs", proofForm())->assertNotFound();
    $this->actingAs($other)->post("/reservations/{$hold->id}/proofs", [])->assertNotFound();
    $this->actingAs(landlordUser())->post("/reservations/{$hold->id}/proofs", proofForm())->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->post("/reservations/{$hold->id}/proofs", proofForm())->assertRedirect('/login');

    expect(PaymentProof::query()->count())->toBe(0)->and($hold->fresh()?->status)->toBe(ReservationStatus::Held);
});

it('removes the file when the database write fails, and leaves the reservation as it was', function (): void {
    $hold = liveHold(User::factory()->renter()->create());

    // A reference number longer than the column can hold makes the insert fail after the file is written.
    expect(fn () => app(UploadPaymentProof::class)->handle($hold->renter, $hold, proofUpload(), ProofFileType::Png, 150000, str_repeat('x', 300)))
        ->toThrow(QueryException::class);

    expect(Storage::disk('proofs')->allFiles())->toBeEmpty()
        ->and($hold->fresh()?->status)->toBe(ReservationStatus::Held);
});

it('locks the reservation row first, and reads its status from the locked row', function (): void {
    $hold = liveHold(User::factory()->renter()->create());
    $renter = $hold->renter;
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    app(UploadPaymentProof::class)->handle($renter, $hold, proofUpload(), ProofFileType::Png, 150000, 'REF');

    expect($statements[0])->toContain('from `reservations`')->toContain('for update');
});
