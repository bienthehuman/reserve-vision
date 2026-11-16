import { Form } from 'react-bootstrap';
import { ExclamationCircle } from 'react-bootstrap-icons';
import type { ReactNode } from 'react';
import { fieldId } from './TextField';

export interface SelectOption {
    value: string;
    label: string;
    group?: string | null;
}

/** Consecutive options of the same group, for `<optgroup>`s; options without a group stand alone. */
export function groupOptions(options: readonly SelectOption[]): { group: string | null; options: SelectOption[] }[] {
    const groups: { group: string | null; options: SelectOption[] }[] = [];
    for (const option of options) {
        const group = option.group ?? null;
        const last = groups[groups.length - 1];
        if (last !== undefined && last.group === group) {
            last.options.push(option);
        } else {
            groups.push({ group, options: [option] });
        }
    }

    return groups;
}

export interface SelectFieldProps {
    /** The form field name; the select's id is `field-<name>`, which the error summary links to. */
    name: string;
    label: ReactNode;
    value: string;
    onChange: (value: string) => void;
    /** Options with a `group` are shown under an `<optgroup>` of that label, in the order given. */
    options: readonly SelectOption[];
    /** A first, empty option such as "Choose a city". */
    placeholder?: string;
    error?: string;
    help?: ReactNode;
    required?: boolean;
    disabled?: boolean;
    className?: string;
}

/** A native select (DESIGN_SYSTEM.md, "Forms"), labelled above, help and error below, like TextField. */
export default function SelectField({
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
}: SelectFieldProps) {
    const id = fieldId(name);
    const helpId = help ? `${id}-help` : undefined;
    const errorId = error ? `${id}-error` : undefined;

    return (
        <Form.Group className={className} controlId={id}>
            <Form.Label>{label}</Form.Label>
            <Form.Select
                name={name}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                isInvalid={!!error}
                aria-invalid={error ? true : undefined}
                aria-describedby={[errorId, helpId].filter(Boolean).join(' ') || undefined}
                required={required}
                disabled={disabled}
            >
                {placeholder !== undefined && <option value="">{placeholder}</option>}
                {groupOptions(options).map(({ group, options: members }) =>
                    group === null ? (
                        members.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))
                    ) : (
                        <optgroup key={`group-${group}`} label={group}>
                            {members.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </optgroup>
                    ),
                )}
            </Form.Select>
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
