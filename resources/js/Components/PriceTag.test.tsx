import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import PriceTag from './PriceTag';

afterEach(cleanup);

describe('PriceTag', () => {
    it('renders a monthly rate with the period', () => {
        const { container } = render(<PriceTag centavos={650000} per="month" />);

        expect(container.textContent).toBe('₱6,500 / month');
    });

    it('renders a fee with centavos and no period', () => {
        const { container } = render(<PriceTag centavos={150000} />);

        expect(container.textContent).toBe('₱1,500.00');
    });
});
