import { formatPeso } from '@/lib/money';

interface PriceTagProps {
    /** Integer centavos, never a preformatted string. */
    centavos: number;
    /** "month" for a rate (₱6,500 / month); omit for a one-off fee (₱1,500.00). */
    per?: 'month';
}

export default function PriceTag({ centavos, per }: PriceTagProps) {
    return (
        <span className="price-tag">
            <span className="price-tag__amount">{formatPeso(centavos, per === 'month' ? 'monthly' : 'fee')}</span>
            {per === 'month' && <span className="price-tag__period"> / month</span>}
        </span>
    );
}
