<?php

declare(strict_types=1);

use App\Actions\RemoveMember;
use App\Enums\LandlordRole;
use App\Enums\ProofFileType;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
| T9 (BUILD_PLAN.md): a signed proof URL used by another user, or after it expires, is denied.
|
| `GET /proofs/{proof}` asks the policy and redirects to a signed URL valid 5 minutes that names the
| proof and the user it was made for. The signed route checks the signature and expiry, that the
| logged-in user is that user, and the policy again (docs/ACCESS.md "Private files"). Every way of
| misusing a URL below must be refused (403 or 404) and must never return the file's bytes; the
| rightful renter and the account's owner and caretaker get exactly the bytes, with the headers.
*/

beforeEach(function (): void {
    fakeMediaDisks();
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

/** The bytes of the proof in the world: a PNG receipt that appears in no other response. */
const T9_BYTES_MARKER = 'T9-PROOF-BYTES';

/**
 * Account A (owner, caretaker) with renter R's proof; renter R2; account B's owner and caretaker.
 *
 * @return array<string, mixed>
 */
function t9World(): array
{
    $w = [];
    $w['ownerA'] = landlordUser();
    $a = accountIdOf($w['ownerA']);
    $w['caretakerA'] = landlordUserOf($a, LandlordRole::Caretaker);
    $w['renter'] = User::factory()->renter()->create();
    $w['otherRenter'] = User::factory()->renter()->create();
    $w['ownerB'] = landlordUser();
    $w['caretakerB'] = landlordUserOf(accountIdOf($w['ownerB']), LandlordRole::Caretaker);

    $room = Room::factory()->for(Property::factory()->published()->state(['landlord_account_id' => $a]))->create();
    $reservation = Reservation::factory()->for($room->spaces()->sole(), 'space')->for($w['renter'], 'renter')->proofUploaded()->create();
    $w['proof'] = PaymentProof::query()->where('reservation_id', $reservation->id)->sole();
    $w['bytes'] = photoBytes(300, 500, 'png').T9_BYTES_MARKER; // trailing bytes after IEND: still the exact file
    $w['proof']->forceFill(['path' => 'proofs/'.Str::uuid().'.png', 'mime_type' => ProofFileType::Png])->save();
    Storage::disk(PaymentProof::DISK)->put($w['proof']->path, $w['bytes']);

    // Another proof of the same renter, for tampering with the id.
    $second = Reservation::factory()->for(Room::factory()->for(Property::factory()->published()->state(['landlord_account_id' => $a]))->create()->spaces()->sole(), 'space')
        ->for($w['renter'], 'renter')->proofUploaded()->create();
    $w['secondProof'] = PaymentProof::query()->where('reservation_id', $second->id)->sole();
    Storage::disk(PaymentProof::DISK)->put($w['secondProof']->path, 'second proof bytes');

    return $w;
}

/** The signed URL `/proofs/{proof}` hands `$user` (asserting the redirect). */
function t9SignedUrl(mixed $test, User $user, PaymentProof $proof): string
{
    $response = $test->actingAs($user)->get("/proofs/{$proof->id}");
    $response->assertRedirect();
    app('auth')->forgetGuards();

    $location = (string) $response->headers->get('Location');
    expect($location)->toContain("/proofs/{$proof->id}/file?")->toContain('signature=')->toContain('expires=')->toContain("user={$user->id}");

    return $location;
}

function t9AssertDenied(TestResponse $response, string $what, array $w): void
{
    expect($response->getStatusCode())->toBeIn([403, 404], "{$what}: expected 403 or 404, got {$response->getStatusCode()}");

    $body = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
    expect($body)->not->toContain(T9_BYTES_MARKER, "{$what}: the file's bytes were sent");
}

function t9AssertServed(TestResponse $response, PaymentProof $proof, string $bytes): void
{
    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Disposition', "inline; filename=\"proof-{$proof->id}.png\"")
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', 'sandbox')
        ->assertHeader('Content-Length', (string) strlen($bytes));

    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')
        ->and($response->streamedContent())->toBe($bytes);
}

it('T9: the renter, and the owner and caretaker of the account, get exactly the bytes with the right headers', function (): void {
    $w = t9World();

    foreach (['renter', 'ownerA', 'caretakerA'] as $who) {
        $url = t9SignedUrl($this, $w[$who], $w['proof']);

        t9AssertServed($this->actingAs($w[$who])->get($url), $w['proof'], $w['bytes']);
        $this->app['auth']->forgetGuards();
    }
});

it('T9: a signed URL used by another logged-in user is denied (another renter, another account, even a member of the same account)', function (): void {
    $w = t9World();
    $url = t9SignedUrl($this, $w['renter'], $w['proof']);

    foreach (['otherRenter', 'ownerB', 'caretakerB', 'ownerA', 'caretakerA'] as $who) {
        t9AssertDenied($this->actingAs($w[$who])->get($url), "the renter's URL used by {$who}", $w);
        $this->app['auth']->forgetGuards();
    }
});

it('T9: a signed URL used by a guest is denied with 403, not a login page', function (): void {
    $w = t9World();
    $url = t9SignedUrl($this, $w['renter'], $w['proof']);

    $response = $this->get($url);

    t9AssertDenied($response, 'a guest', $w);
    $response->assertForbidden();
});

it('T9: a signed URL is denied once it has expired (5 minutes)', function (): void {
    $w = t9World();
    $url = t9SignedUrl($this, $w['renter'], $w['proof']);

    $this->travel(4)->minutes();
    $this->actingAs($w['renter'])->get($url)->assertOk();

    $this->travel(61)->seconds();
    t9AssertDenied($this->actingAs($w['renter'])->get($url), 'after 5 minutes', $w);
});

it('T9: a tampered URL is denied: another proof id, another user id, a later expiry, no signature', function (): void {
    $w = t9World();
    $url = t9SignedUrl($this, $w['renter'], $w['proof']);
    $id = $w['proof']->id;

    $tampered = [
        'another proof of the same renter' => str_replace("/proofs/{$id}/file", "/proofs/{$w['secondProof']->id}/file", $url),
        'another user (the other renter)' => preg_replace('/user=\d+/', "user={$w['otherRenter']->id}", $url),
        'a later expiry' => preg_replace_callback('/expires=(\d+)/', fn (array $m): string => 'expires='.((int) $m[1] + 3600), $url),
        'no signature' => preg_replace('/&?signature=[0-9a-f]+/', '', $url),
    ];

    foreach ($tampered as $what => $tamperedUrl) {
        expect($tamperedUrl)->not->toBe($url);
        $as = str_contains($what, 'another user') ? $w['otherRenter'] : $w['renter'];

        t9AssertDenied($this->actingAs($as)->get((string) $tamperedUrl), $what, $w);
        $this->app['auth']->forgetGuards();
    }
});

it('T9: a URL made before the viewer lost access is denied: a removed caretaker', function (): void {
    $w = t9World();
    $url = t9SignedUrl($this, $w['caretakerA'], $w['proof']);

    $this->actingAs($w['caretakerA'])->get($url)->assertOk();
    $this->app['auth']->forgetGuards();

    $this->actingAs($w['ownerA']);
    app(RemoveMember::class)->handle($w['caretakerA']->landlordMember()->sole(), $w['ownerA']);
    $this->app['auth']->forgetGuards();

    // A fresh copy of the user, as the next real request would load: the old object still caches its membership.
    t9AssertDenied($this->actingAs($w['caretakerA']->fresh())->get($url), 'a removed caretaker', $w);
});

it('T9: nobody but the renter and the account gets a signed URL at all', function (): void {
    $w = t9World();

    foreach (['otherRenter', 'ownerB', 'caretakerB'] as $who) {
        $response = $this->actingAs($w[$who])->get("/proofs/{$w['proof']->id}");
        $response->assertNotFound();
        expect((string) $response->headers->get('Location'))->not->toContain('signature');
        $this->app['auth']->forgetGuards();
    }

    $this->get("/proofs/{$w['proof']->id}")->assertRedirect('/login');
});

it('serves a PDF as a PDF, and answers 404 when the file is missing', function (): void {
    $w = t9World();
    $pdf = pdfBytes(2);
    $w['proof']->forceFill(['path' => 'proofs/'.Str::uuid().'.pdf', 'mime_type' => ProofFileType::Pdf])->save();
    Storage::disk(PaymentProof::DISK)->put($w['proof']->path, $pdf);

    $url = t9SignedUrl($this, $w['renter'], $w['proof']);
    $response = $this->actingAs($w['renter'])->get($url);
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', "inline; filename=\"proof-{$w['proof']->id}.pdf\"");
    expect($response->streamedContent())->toBe($pdf);

    Storage::disk(PaymentProof::DISK)->delete($w['proof']->path);
    $url = t9SignedUrl($this, $w['renter'], $w['proof']);
    $this->actingAs($w['renter'])->get($url)->assertNotFound();
});

it('keeps the redirect to the signed URL out of caches', function (): void {
    $w = t9World();

    $response = $this->actingAs($w['renter'])->get("/proofs/{$w['proof']->id}");

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});
