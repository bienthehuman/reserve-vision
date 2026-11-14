/**
 * Money is an integer number of centavos everywhere except the view edge.
 * This is the only place it becomes a string (docs/CONVENTIONS.md).
 */
export type PesoStyle = 'fee' | 'monthly';

const formatters: Record<PesoStyle, Intl.NumberFormat> = {
    // Fees: always two decimals, e.g. ₱1,500.00
    fee: new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }),
    // Monthly rates: whole pesos, e.g. ₱6,500
    monthly: new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }),
};

export function formatPeso(centavos: number, style: PesoStyle = 'fee'): string {
    if (!Number.isSafeInteger(centavos)) {
        throw new RangeError(`formatPeso expects an integer number of centavos, got ${centavos}`);
    }

    // A monthly rate with centavos is shown in full rather than silently rounded.
    const formatter = style === 'monthly' && centavos % 100 !== 0 ? formatters.fee : formatters[style];

    return formatter.format(centavos / 100);
}
