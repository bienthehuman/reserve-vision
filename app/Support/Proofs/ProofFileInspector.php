<?php

declare(strict_types=1);

namespace App\Support\Proofs;

use App\Enums\ProofFileType;
use App\Support\Photos\PhotoProcessor;

/**
 * Judges a payment proof file by its CONTENT (slice 5.3), never by its name or the MIME type the
 * browser sent: the first bytes say what it is, an image must decode completely, and a PDF must be
 * readable enough to count its pages. The file itself is never changed: it is evidence.
 */
final class ProofFileInspector
{
    /** The largest proof, in bytes. */
    public const int MAX_BYTES = 5 * 1024 * 1024;

    /** At most this much is inflated from one compressed PDF object stream, and from all of them together. */
    private const int MAX_INFLATED = 20 * 1024 * 1024;

    public function __construct(private readonly PhotoProcessor $photos) {}

    /** What the first bytes say the file is, or null for anything but a JPEG, PNG, WebP or PDF. */
    public function detect(string $path): ?ProofFileType
    {
        $head = @file_get_contents($path, length: 16);

        if ($head === false) {
            return null;
        }

        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => ProofFileType::Jpeg,
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => ProofFileType::Png,
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => ProofFileType::Webp,
            str_starts_with($head, '%PDF-') => ProofFileType::Pdf,
            default => null,
        };
    }

    /** Whether the image's header declares more pixels than PhotoProcessor will decode. */
    public function tooManyPixels(string $path): bool
    {
        $image = $this->photos->probe($path);

        return $image !== null && $image['width'] * $image['height'] > PhotoProcessor::MAX_PIXELS;
    }

    /** Whether a file of `$type` reads: an image decodes as that type, a PDF has at least one page. */
    public function reads(string $path, ProofFileType $type): bool
    {
        if ($type === ProofFileType::Pdf) {
            $bytes = @file_get_contents($path);

            return $bytes !== false && $this->pdfPageCount($bytes) !== null;
        }

        $image = $this->photos->probe($path);

        return $image !== null
            && $image['type'] === match ($type) {
                ProofFileType::Jpeg => 'JPEG',
                ProofFileType::Png => 'PNG',
                default => 'WebP',
            }
        && $this->photos->decodes($path);
    }

    /**
     * The number of pages of a PDF, or null when it can't be read: no `%PDF-` header or `%%EOF`
     * trailer, encrypted, or no page object found. Pages are counted as `/Type /Page` objects, in the
     * file and, for PDF 1.5+ files that pack their objects into compressed object streams, inside
     * those streams too (inflated with a size cap, so a "zip bomb" can't exhaust memory). Not a full
     * parser: enough to refuse a renamed file, a truncated download or a password-protected PDF.
     */
    public function pdfPageCount(string $bytes): ?int
    {
        if (! str_starts_with($bytes, '%PDF-') || ! str_contains(substr($bytes, -1024), '%%EOF')) {
            return null;
        }

        if (preg_match('#/Encrypt\b#', $bytes) === 1) {
            return null;
        }

        $pages = $this->countPageObjects($bytes);

        if ($pages === 0) {
            $pages = $this->countPageObjects($this->inflatedObjectStreams($bytes));
        }

        return $pages > 0 ? $pages : null;
    }

    private function countPageObjects(string $text): int
    {
        return (int) preg_match_all('#/Type\s*/Page(?![A-Za-z])#', $text);
    }

    /** The decompressed content of every `/Type /ObjStm` stream with `/FlateDecode`, joined. */
    private function inflatedObjectStreams(string $bytes): string
    {
        preg_match_all('#/Type\s*/ObjStm\b#', $bytes, $matches, PREG_OFFSET_CAPTURE);

        $text = '';

        foreach ($matches[0] as [, $offset]) {
            // The object's dictionary runs from its `obj` keyword to the `stream` keyword after the type.
            $objectStart = strrpos(substr($bytes, max(0, $offset - 2000), min($offset, 2000)), 'obj');
            $streamKeyword = strpos($bytes, 'stream', $offset);

            if ($objectStart === false || $streamKeyword === false || $streamKeyword - $offset > 2000) {
                continue;
            }

            $dictionary = substr($bytes, max(0, $offset - 2000) + $objectStart, $streamKeyword - (max(0, $offset - 2000) + $objectStart));

            if (! str_contains($dictionary, '/FlateDecode')) {
                continue;
            }

            $start = $streamKeyword + strlen('stream');
            $start += substr($bytes, $start, 2) === "\r\n" ? 2 : 1;
            $end = strpos($bytes, 'endstream', $start);

            if ($end === false) {
                continue;
            }

            $inflated = @zlib_decode(substr($bytes, $start, $end - $start), self::MAX_INFLATED);

            if (is_string($inflated)) {
                $text .= $inflated;
            }

            if (strlen($text) > self::MAX_INFLATED) {
                break;
            }
        }

        return $text;
    }
}
