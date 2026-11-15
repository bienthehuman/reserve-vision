import { describe, expect, it } from 'vitest';
import { formatPeso, pesosText } from './money';

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

describe('pesosText', () => {
    it('writes whole pesos without decimals and keeps centavos', () => {
        expect(pesosText(650000)).toBe('6500');
        expect(pesosText(650050)).toBe('6500.50');
        expect(pesosText(0)).toBe('0');
        expect(pesosText(5)).toBe('0.05');
    });
});
