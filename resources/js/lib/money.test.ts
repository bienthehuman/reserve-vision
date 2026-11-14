import { describe, expect, it } from 'vitest';
import { formatPeso } from './money';

describe('formatPeso', () => {
    it('formats a fee with two decimals', () => {
        expect(formatPeso(150000)).toBe('₱1,500.00');
    });

    it('formats a monthly rate in whole pesos', () => {
        expect(formatPeso(650000, 'monthly')).toBe('₱6,500');
    });

    it('formats zero and small amounts', () => {
        expect(formatPeso(0)).toBe('₱0.00');
        expect(formatPeso(5)).toBe('₱0.05');
    });

    it('groups thousands for large amounts', () => {
        expect(formatPeso(123456789, 'fee')).toBe('₱1,234,567.89');
    });

    it('does not round away centavos on a monthly rate', () => {
        expect(formatPeso(650050, 'monthly')).toBe('₱6,500.50');
    });

    it('rejects non-integer centavos', () => {
        expect(() => formatPeso(1500.5)).toThrow(RangeError);
        expect(() => formatPeso(Number.NaN)).toThrow(RangeError);
    });
});
