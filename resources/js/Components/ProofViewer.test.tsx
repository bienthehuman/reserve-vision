import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import ProofViewer from './ProofViewer';

afterEach(cleanup);

describe('ProofViewer', () => {
    it('shows an image inline through /proofs/{id}, and opens it full size in a new tab', () => {
        render(<ProofViewer proof={{ id: 7, url: '/proofs/7', is_image: true }} label="Proof of payment from Ana Cruz" />);

        const image = screen.getByRole('img', { name: 'Proof of payment from Ana Cruz' });
        expect(image.getAttribute('src')).toBe('/proofs/7');
        const link = image.closest('a');
        expect(link?.getAttribute('href')).toBe('/proofs/7');
        expect(link?.getAttribute('target')).toBe('_blank');
        expect(link?.textContent).toContain('Open full size');
    });

    it('shows a PDF as a link, never inline', () => {
        render(<ProofViewer proof={{ id: 8, url: '/proofs/8', is_image: false }} label="Proof of payment from Ana Cruz" />);

        expect(screen.queryByRole('img')).toBeNull();
        const link = screen.getByRole('link', { name: /^Open the PDF/ });
        expect(link.getAttribute('href')).toBe('/proofs/8');
    });
});
