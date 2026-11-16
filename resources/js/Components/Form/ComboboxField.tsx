import { useCombobox } from 'downshift';
import { useState, type ReactNode } from 'react';
import { Form } from 'react-bootstrap';
import { Check2, ExclamationCircle } from 'react-bootstrap-icons';
import { fieldId } from './TextField';

export interface ComboboxOption {
    value: string;
    label: string;
    /** A heading the option is listed under (Manila's district); typing it finds the option too. */
    group?: string | null;
}

/** Lowercase, without accents, so "dona" finds "Doña Imelda" and "las pinas" finds "Las Piñas". */
export function normalize(text: string): string {
    return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/** The options whose label and group contain every word typed, in any order: "395 sampaloc", "sampaloc 395". */
export function filterOptions<T extends ComboboxOption>(options: readonly T[], query: string): T[] {
    const words = normalize(query).split(/\s+/).filter((word) => word !== '');

    if (words.length === 0) {
        return [...options];
    }

    return options.filter((option) => {
        const text = normalize(`${option.label} ${option.group ?? ''}`);

        return words.every((word) => text.includes(word));
    });
}

export interface ComboboxFieldProps {
    /** The form field name; the input's id is `field-<name>`, which the error summary links to. */
    name: string;
    label: string;
    /** The chosen option's value, '' for none. */
    value: string;
    onChange: (value: string) => void;
    options: readonly ComboboxOption[];
    /** Shown in the empty field: what to do, or why it is disabled ("Choose a city first."). */
    placeholder?: string;
    error?: string;
    help?: ReactNode;
    required?: boolean;
    disabled?: boolean;
    className?: string;
}

/**
 * A dropdown you can type into (DESIGN_SYSTEM.md, "Dropdowns"): for lists too long to scroll, such as
 * Manila's 897 barangays. Typing narrows the list (accents and word order don't matter), arrows and Enter
 * choose, Escape closes; leaving the field without choosing puts the chosen name back. Built on Downshift's
 * `useCombobox` (the ARIA 1.2 combobox pattern, no styles of its own, so nothing the CSP forbids); options
 * with a `group` are listed under its heading, and their accessible name carries it ("Barangay 395, Sampaloc").
 */
export default function ComboboxField({
    name,
    label,
    value,
    onChange,
    options,
    placeholder,
    error,
    help,
    required = false,
    disabled = false,
    className = 'mb-3',
}: ComboboxFieldProps) {
    const id = fieldId(name);
    const helpId = help ? `${id}-help` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const selectedItem = options.find((option) => option.value === value) ?? null;
    // What the person has typed since the list opened; null shows the whole list.
    const [query, setQuery] = useState<string | null>(null);
    const items = query === null ? [...options] : filterOptions(options, query);
    const itemToString = (item: ComboboxOption | null) => item?.label ?? '';

    const { isOpen, highlightedIndex, getLabelProps, getInputProps, getMenuProps, getItemProps, getToggleButtonProps } = useCombobox<ComboboxOption>({
        items,
        selectedItem,
        itemToString,
        inputId: id,
        labelId: `${id}-label`,
        menuId: `${id}-list`,
        toggleButtonId: `${id}-toggle`,
        getItemId: (index) => `${id}-option-${index}`,
        onSelectedItemChange: ({ selectedItem: item }) => onChange(item?.value ?? ''),
        onInputValueChange: ({ inputValue, type }) => {
            if (type === useCombobox.stateChangeTypes.InputChange) {
                setQuery(inputValue ?? '');
            }
        },
        onIsOpenChange: ({ isOpen: open }) => {
            if (!open) {
                setQuery(null);
            }
        },
        stateReducer: (state, { type, changes }) =>
            // Leaving the field: the text goes back to the chosen name, never a half-typed search.
            type === useCombobox.stateChangeTypes.InputBlur ? { ...changes, inputValue: itemToString(changes.selectedItem ?? state.selectedItem) } : changes,
    });

    const rows: ReactNode[] = [];
    let group: string | null = null;
    items.forEach((item, index) => {
        if ((item.group ?? null) !== group) {
            group = item.group ?? null;
            if (group !== null) {
                // The heading is for the eye; the option's aria-label carries the group for a screen reader.
                rows.push(
                    <li key={`group-${group}`} className="combobox__group" role="presentation" aria-hidden="true">
                        {group}
                    </li>,
                );
            }
        }
        const chosen = item.value === value;
        rows.push(
            <li
                key={item.value}
                {...getItemProps({
                    item,
                    index,
                    className: ['combobox__option', highlightedIndex === index ? 'is-active' : '', chosen ? 'is-chosen' : ''].filter(Boolean).join(' '),
                    // The heading is hidden from screen readers, so the option's name carries the group.
                    'aria-label': item.group ? `${item.label}, ${item.group}` : undefined,
                })}
            >
                <span>{item.label}</span>
                {chosen && <Check2 aria-hidden="true" focusable="false" className="combobox__tick" />}
            </li>,
        );
    });

    return (
        <Form.Group className={className}>
            <label {...getLabelProps({ className: 'form-label' })}>{label}</label>
            <div className="combobox">
                <input
                    {...getInputProps({
                        name,
                        className: `form-control combobox__input${error ? ' is-invalid' : ''}`,
                        placeholder,
                        disabled,
                        required,
                        autoComplete: 'off',
                        'aria-invalid': error ? true : undefined,
                        'aria-describedby': [errorId, helpId].filter(Boolean).join(' ') || undefined,
                    })}
                />
                <button
                    type="button"
                    {...getToggleButtonProps({ className: 'combobox__toggle', disabled, tabIndex: -1, 'aria-label': `Show the list: ${label}` })}
                />
                <ul {...getMenuProps({ className: `combobox__menu${isOpen ? ' is-open' : ''}` })}>
                    {isOpen &&
                        (items.length === 0 ? (
                            <li className="combobox__empty" role="presentation">
                                Nothing matches “{query}”.
                            </li>
                        ) : (
                            rows
                        ))}
                </ul>
            </div>
            {error && (
                <Form.Control.Feedback type="invalid" id={errorId} className="d-flex">
                    <ExclamationCircle aria-hidden="true" focusable="false" className="flex-shrink-0" />
                    <span>{error}</span>
                </Form.Control.Feedback>
            )}
            {help && (
                <Form.Text id={helpId} className="d-block">
                    {help}
                </Form.Text>
            )}
        </Form.Group>
    );
}
