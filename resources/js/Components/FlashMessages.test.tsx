import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { FlashError, FlashToast, TOAST_MS } from './FlashMessages';

let flash: { status: string | null; error: string | null } = { status: null, error: null };

vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { flash } }) }));

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

describe('FlashToast', () => {
    it('shows a confirmation as a status, and closes itself after six seconds', () => {
        flash = { status: 'Saved Room 1.', error: null };
        render(<FlashToast />);

        expect(screen.getByRole('status').textContent).toBe('Saved Room 1.');

        act(() => vi.advanceTimersByTime(TOAST_MS - 1));
        expect(screen.queryByRole('status')).not.toBeNull();

        act(() => vi.advanceTimersByTime(1));
        expect(screen.queryByRole('status')).toBeNull();
    });

    it('waits while the pointer or the keyboard focus is on it', () => {
        flash = { status: 'Saved Room 1.', error: null };
        render(<FlashToast />);

        fireEvent.mouseEnter(screen.getByRole('status'));
        act(() => vi.advanceTimersByTime(TOAST_MS * 3));
        expect(screen.queryByRole('status')).not.toBeNull();

        fireEvent.mouseLeave(screen.getByRole('status'));
        fireEvent.focus(screen.getByRole('button', { name: 'Dismiss this message' }));
        act(() => vi.advanceTimersByTime(TOAST_MS * 3));
        expect(screen.queryByRole('status')).not.toBeNull();

        fireEvent.blur(screen.getByRole('button', { name: 'Dismiss this message' }));
        act(() => vi.advanceTimersByTime(TOAST_MS));
        expect(screen.queryByRole('status')).toBeNull();
    });

    it('closes on its button, and shows the same sentence again after the next action', () => {
        flash = { status: 'Saved Room 1.', error: null };
        const { rerender } = render(<FlashToast />);

        fireEvent.click(screen.getByRole('button', { name: 'Dismiss this message' }));
        expect(screen.queryByRole('status')).toBeNull();

        flash = { status: 'Saved Room 1.', error: null }; // a new response
        rerender(<FlashToast />);
        expect(screen.getByRole('status').textContent).toBe('Saved Room 1.');
    });

    it('renders nothing without a confirmation', () => {
        flash = { status: null, error: 'No.' };
        const { container } = render(<FlashToast />);

        expect(container.innerHTML).toBe('');
    });
});

describe('FlashError', () => {
    it('takes focus, and stays until it is dismissed: an error must be read', () => {
        flash = { status: null, error: 'Room 1 can\'t be archived.' };
        render(<FlashError />);

        expect(document.activeElement).toBe(screen.getByRole('alert')); // focus moves to it, like a form's error summary

        act(() => vi.advanceTimersByTime(TOAST_MS * 10));
        expect(screen.getByRole('alert').textContent).toBe('Room 1 can\'t be archived.');

        fireEvent.click(screen.getByRole('button', { name: 'Dismiss this message' }));
        expect(screen.queryByRole('alert')).toBeNull();
    });
});
