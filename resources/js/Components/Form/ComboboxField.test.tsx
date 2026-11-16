import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ComboboxField, { filterOptions, type ComboboxOption } from './ComboboxField';

const barangays: ComboboxOption[] = [
    { value: '1', label: 'Barangay 659', group: 'Ermita' },
    { value: '2', label: 'Barangay 395', group: 'Sampaloc' },
    { value: '3', label: 'Barangay 396', group: 'Sampaloc' },
    { value: '4', label: 'Doña Imelda', group: null },
];

function Harness({ onChange = vi.fn(), start = '' }: { onChange?: (value: string) => void; start?: string }) {
    const [value, setValue] = useState(start);

    return (
        <ComboboxField
            name="barangay_id"
            label="Barangay"
            value={value}
            onChange={(next) => {
                setValue(next);
                onChange(next);
            }}
            options={barangays}
            placeholder="Type or choose the barangay"
        />
    );
}

afterEach(cleanup);

describe('filterOptions (slice 4.4)', () => {
    it('matches every word, in any order, in the label or the group, ignoring accents and case', () => {
        expect(filterOptions(barangays, 'dona').map((o) => o.label)).toEqual(['Doña Imelda']);
        expect(filterOptions(barangays, 'SAMPALOC 396').map((o) => o.label)).toEqual(['Barangay 396']);
        expect(filterOptions(barangays, '  ')).toHaveLength(4);
        expect(filterOptions(barangays, 'tondo')).toEqual([]);
    });
});

describe('ComboboxField', () => {
    it('is a labelled combobox whose list opens with the groups as headings and the group in each option\'s name', () => {
        render(<Harness />);
        const input = screen.getByRole('combobox', { name: 'Barangay' });
        expect(input.id).toBe('field-barangay_id');

        fireEvent.click(input);
        const list = screen.getByRole('listbox');
        expect(within(list).getByRole('option', { name: 'Barangay 395, Sampaloc' })).toBeTruthy();
        expect(within(list).getAllByRole('option')).toHaveLength(4);
        expect(within(list).getByText('Sampaloc', { selector: '.combobox__group' })).toBeTruthy();
    });

    it('narrows the list as you type and chooses with the keyboard', () => {
        const onChange = vi.fn();
        render(<Harness onChange={onChange} />);
        const input = screen.getByRole('combobox', { name: 'Barangay' });

        fireEvent.change(input, { target: { value: 'sampaloc' } });
        expect(within(screen.getByRole('listbox')).getAllByRole('option').map((o) => o.getAttribute('aria-label'))).toEqual(['Barangay 395, Sampaloc', 'Barangay 396, Sampaloc']);

        fireEvent.keyDown(input, { key: 'ArrowDown' });
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(onChange).toHaveBeenCalledWith('2');
        expect((input as HTMLInputElement).value).toBe('Barangay 395');
    });

    it('says when nothing matches, and puts the chosen name back when you leave without choosing', () => {
        render(<Harness start="1" />);
        const input = screen.getByRole('combobox', { name: 'Barangay' });
        expect((input as HTMLInputElement).value).toBe('Barangay 659');

        fireEvent.change(input, { target: { value: 'atlantis' } });
        expect(screen.getByText('Nothing matches “atlantis”.')).toBeTruthy();

        fireEvent.blur(input);
        expect((input as HTMLInputElement).value).toBe('Barangay 659');
    });
});
