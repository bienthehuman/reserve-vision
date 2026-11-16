import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import ViewingForm, { type ViewingData } from './ViewingForm';

const times = Array.from({ length: 21 }, (_, i) => {
    const minutes = 8 * 60 + i * 30;

    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
});

const sameDay: ViewingData = {
    earliest: { date: '2027-03-15', time: '12:00' },
    dates: ['2027-03-15', '2027-03-16', '2027-03-17'],
    times,
    max_message: 500,
    open: null,
};

const props = { propertyId: 7, propertyName: 'Casa Verde', today: '2027-03-15', csrf: 'token', loggedIn: true, isLandlord: false, errors: [] as string[] };

afterEach(cleanup);

describe('ViewingForm', () => {
    it('starts at the earliest open slot, which is today while one is left', () => {
        render(<ViewingForm {...props} viewing={sameDay} />);

        expect((screen.getByLabelText('Day') as HTMLSelectElement).value).toBe('2027-03-15');
        expect((screen.getByLabelText('Time (Manila)') as HTMLSelectElement).value).toBe('12:00');
        expect(screen.getByRole('option', { name: 'Today, Mon 15 Mar' })).toBeTruthy();
    });

    it('disables the times before the earliest one on that day only', () => {
        render(<ViewingForm {...props} viewing={sameDay} />);

        expect((screen.getByRole('option', { name: '11:30 AM' }) as HTMLOptionElement).disabled).toBe(true);
        expect((screen.getByRole('option', { name: '8:00 AM' }) as HTMLOptionElement).disabled).toBe(true);
        expect((screen.getByRole('option', { name: '12:00 PM' }) as HTMLOptionElement).disabled).toBe(false);

        fireEvent.change(screen.getByLabelText('Day'), { target: { value: '2027-03-16' } });

        expect((screen.getByRole('option', { name: '8:00 AM' }) as HTMLOptionElement).disabled).toBe(false);
    });

    it('moves a too-early time up to the earliest when the day goes back to today', () => {
        render(<ViewingForm {...props} viewing={sameDay} />);

        fireEvent.change(screen.getByLabelText('Day'), { target: { value: '2027-03-16' } });
        fireEvent.change(screen.getByLabelText('Time (Manila)'), { target: { value: '08:00' } });
        fireEvent.change(screen.getByLabelText('Day'), { target: { value: '2027-03-15' } });

        expect((screen.getByLabelText('Time (Manila)') as HTMLSelectElement).value).toBe('12:00');
    });

    it('starts tomorrow at 8 AM once today has no slot left, and says so', () => {
        const late: ViewingData = { ...sameDay, earliest: { date: '2027-03-16', time: '08:00' }, dates: ['2027-03-16', '2027-03-17'] };
        render(<ViewingForm {...props} viewing={late} />);

        expect((screen.getByLabelText('Day') as HTMLSelectElement).value).toBe('2027-03-16');
        expect((screen.getByLabelText('Time (Manila)') as HTMLSelectElement).value).toBe('08:00');
        expect((screen.getByRole('option', { name: '8:00 AM' }) as HTMLOptionElement).disabled).toBe(false);
        expect(screen.getByText(/no more times left today/)).toBeTruthy();
    });

    it('is a plain form post with the token and the property', () => {
        const { container } = render(<ViewingForm {...props} viewing={sameDay} />);
        const form = container.querySelector('form');

        expect(form?.getAttribute('method')).toBe('post');
        expect(form?.getAttribute('action')).toBe('/viewings');
        expect((form?.querySelector('input[name="_token"]') as HTMLInputElement).value).toBe('token');
        expect((form?.querySelector('input[name="property_id"]') as HTMLInputElement).value).toBe('7');
        expect(form?.querySelector('textarea[name="message"]')?.getAttribute('maxlength')).toBe('500');
    });

    it('points at an open request instead of offering the form again', () => {
        const { container } = render(<ViewingForm {...props} viewing={{ ...sameDay, open: { id: 3, status: 'requested', preferred_at: '2027-03-17T01:00:00Z' } }} />);

        expect(container.querySelector('form')).toBeNull();
        expect(screen.getByRole('link', { name: /View your viewing requests/ }).getAttribute('href')).toBe('/viewings');
        expect(screen.getByText(/Wed 17 Mar/)).toBeTruthy();
    });

    it('offers landlords no form, and shows the errors from a refused post', () => {
        const { container, rerender } = render(<ViewingForm {...props} isLandlord viewing={sameDay} />);

        expect(container.querySelector('form')).toBeNull();

        rerender(<ViewingForm {...props} viewing={sameDay} errors={['The message may not be greater than 500 characters.']} />);

        expect(screen.getByRole('alert').textContent).toContain('500 characters');
    });
});
