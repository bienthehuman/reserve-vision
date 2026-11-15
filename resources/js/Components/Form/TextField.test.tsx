import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import TextField from './TextField';

afterEach(cleanup);

describe('TextField', () => {
    it('shows and hides a password with the button inside the field', () => {
        render(<TextField name="password" type="password" label="Password" value="secret words" onChange={() => {}} />);

        const input = screen.getByLabelText('Password', { selector: 'input' });
        const toggle = screen.getByRole('button', { name: 'Show password' });
        expect(input.getAttribute('type')).toBe('password');
        expect(toggle.getAttribute('aria-pressed')).toBe('false');
        expect(toggle.getAttribute('aria-controls')).toBe('field-password');

        fireEvent.click(toggle);
        expect(input.getAttribute('type')).toBe('text');
        expect(toggle.getAttribute('aria-pressed')).toBe('true');

        fireEvent.click(toggle);
        expect(input.getAttribute('type')).toBe('password');
    });

    it('has no toggle on other fields', () => {
        render(<TextField name="email" type="email" label="Email" value="" onChange={() => {}} />);

        expect(screen.queryByRole('button')).toBeNull();
    });

    it('links the error and help text, and shows the error even inside a password field', () => {
        render(<TextField name="password" type="password" label="Password" value="" onChange={() => {}} error="Too short." help="At least 8 characters." />);

        const input = screen.getByLabelText('Password', { selector: 'input' });
        expect(input.getAttribute('aria-describedby')).toBe('field-password-error field-password-help');
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(screen.getByText('Too short.').closest('.invalid-feedback')?.className).toContain('d-flex');
    });
});
