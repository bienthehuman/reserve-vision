<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Philippine mobile numbers, stored one way: 11 digits, "09XXXXXXXXX".
 *
 * Accepts what people type: "0917 123 4567", "0917-123-4567", "(0917) 1234567",
 * "+63 917 123 4567", "63 917…" and "917 123 4567". Landlines and numbers of the
 * wrong length are rejected, not guessed at.
 */
final class PhilippineMobile
{
    /** "09171234567", or null when the input isn't a PH mobile number. */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        // Only digits and the usual separators; anything else (letters, "ext.") is rejected.
        if (preg_match('/^\+?[\d\s().\-]+$/', trim($input)) !== 1) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $input);

        $local = match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '639') => '0'.substr($digits, 2),
            strlen($digits) === 11 && str_starts_with($digits, '09') => $digits,
            strlen($digits) === 10 && str_starts_with($digits, '9') => '0'.$digits,
            default => null,
        };

        return $local;
    }

    /** "0917 123 4567", for display. */
    public static function format(string $normalized): string
    {
        return substr($normalized, 0, 4).' '.substr($normalized, 4, 3).' '.substr($normalized, 7);
    }
}
