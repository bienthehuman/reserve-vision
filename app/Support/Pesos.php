<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Money typed in pesos ("6500", "6,500" or "6500.50", at most two decimals) to
 * integer centavos, parsed from the string and never through a float.
 */
final class Pesos
{
    public const string PATTERN = '/^\d{1,3}(,\d{3})*(\.\d{1,2})?$|^\d+(\.\d{1,2})?$/';

    /** The money columns are INT UNSIGNED: ₱42,949,672.95. */
    public const int MAX_CENTAVOS = 4_294_967_295;

    public static function matches(string $pesos): bool
    {
        return preg_match(self::PATTERN, $pesos) === 1;
    }

    /** "6,500.5" → 650050. Callers have checked the shape with matches(). */
    public static function toCentavos(string $pesos): int
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '', $pesos), 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /** 650000 → "₱6,500", 650050 → "₱6,500.50": whole pesos unless there are centavos, like the page's `formatPeso()`. */
    public static function format(int $centavos): string
    {
        $whole = intdiv($centavos, 100);
        $cents = $centavos % 100;

        return '₱'.number_format($whole).($cents === 0 ? '' : '.'.str_pad((string) $cents, 2, '0', STR_PAD_LEFT));
    }
}
