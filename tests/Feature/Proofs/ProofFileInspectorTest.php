<?php

declare(strict_types=1);

use App\Enums\ProofFileType;
use App\Support\Proofs\ProofFileInspector;

/** Writes bytes to a temporary file and returns its path. */
function proofTempFile(string $bytes): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'proof');
    file_put_contents($path, $bytes);

    return $path;
}

it('detects the type from the first bytes, whatever the file is called', function (string $bytes, ?ProofFileType $type): void {
    expect(app(ProofFileInspector::class)->detect(proofTempFile($bytes)))->toBe($type);
})->with([
    'jpeg' => fn () => [photoBytes(300, 300), ProofFileType::Jpeg],
    'png' => fn () => [photoBytes(300, 300, 'png'), ProofFileType::Png],
    'webp' => fn () => [photoBytes(300, 300, 'webp'), ProofFileType::Webp],
    'pdf' => fn () => [pdfBytes(), ProofFileType::Pdf],
    'gif' => fn () => [photoBytes(300, 300, 'gif'), null],
    'html' => fn () => ['<html><script>alert(1)</script></html>', null],
    'svg' => fn () => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', null],
    'empty' => fn () => ['', null],
]);

it('counts the pages of a plain PDF and of one with compressed object streams', function (): void {
    $inspector = app(ProofFileInspector::class);

    expect($inspector->pdfPageCount(pdfBytes(1)))->toBe(1)
        ->and($inspector->pdfPageCount(pdfBytes(3)))->toBe(3)
        ->and($inspector->pdfPageCount(pdfBytes(2, objectStreams: true)))->toBe(2);
});

it('refuses a PDF it can\'t read: truncated, encrypted, no pages, or only a header', function (string $bytes): void {
    expect(app(ProofFileInspector::class)->pdfPageCount($bytes))->toBeNull();
})->with([
    'truncated (no %%EOF)' => fn () => substr(pdfBytes(), 0, -20),
    'encrypted' => fn () => str_replace('trailer << /Root 1 0 R', 'trailer << /Encrypt 7 0 R /Root 1 0 R', pdfBytes()),
    'no page objects' => fn () => "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n",
    'just the header' => fn () => "%PDF-1.7\n%%EOF",
    'garbage in an object stream' => fn () => "%PDF-1.5\n9 0 obj << /Type /ObjStm /Filter /FlateDecode >>\nstream\nnot zlib at all\nendstream\nendobj\n%%EOF\n",
]);

it('reads an image only when the whole file decodes as the type its header says', function (): void {
    $inspector = app(ProofFileInspector::class);
    $png = photoBytes(300, 300, 'png');

    expect($inspector->reads(proofTempFile($png), ProofFileType::Png))->toBeTrue()
        ->and($inspector->reads(proofTempFile(substr($png, 0, 60).str_repeat("\0", 200)), ProofFileType::Png))->toBeFalse()
        ->and($inspector->reads(proofTempFile($png), ProofFileType::Jpeg))->toBeFalse();
});
